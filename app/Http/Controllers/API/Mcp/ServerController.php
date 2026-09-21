<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Mcp;

use Throwable;
use App\Models\User;
use Hypervel\Http\Request;
use App\Services\McpService;
use InvalidArgumentException;
use Hypervel\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use App\Http\Controllers\Concerns\ResolvesUserPlan;

/**
 * MCP 伺服器（Streamable HTTP）。
 *
 * 讓使用者把 RSSPilot 的資料接到自己的 AI 工具上：在設定頁產生一把 API key，
 * 在對方的 MCP 設定裡填這個端點與 `Authorization: Bearer <key>` 即可。
 *
 * **只有 POST**，內容是 JSON-RPC 2.0。回應一律 `application/json` 單一物件——
 * 規格允許改用 SSE 串流，但我們的工具都是一次查完就回，開串流沒有好處。
 *
 * **同時支援兩個世代的 MCP**：2026-07-28 起是無狀態、每次請求自帶
 * `_meta` 與 `MCP-Protocol-Version` 標頭；更早的版本要先做 `initialize` 握手並
 * 可能帶 `Mcp-Session-Id`。我們兩者都收：握手照回，session id 直接忽略（本來就
 * 沒有狀態可存），所以新舊客戶端都連得上。
 *
 * 認證走 sanctum guard（見 `config/auth.php`），資料一律從 token 持有者的關聯
 * 出發，一把 key 只讀得到自己帳號的東西。
 */
class ServerController
{
    use ResolvesUserPlan;

    /**
     * 我們實作的協定版本，新到舊。
     *
     * 握手時回客戶端要求的那一版（在清單內的話），否則回最新的——這是規格的
     * 協商方式，硬回自己的版本會讓只支援舊版的客戶端直接斷線。
     */
    private const array PROTOCOL_VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];

    /** JSON-RPC 標準錯誤碼。 */
    private const int ERR_PARSE = -32700;

    private const int ERR_INVALID_REQUEST = -32600;

    private const int ERR_METHOD_NOT_FOUND = -32601;

    private const int ERR_INVALID_PARAMS = -32602;

    private const int ERR_INTERNAL = -32603;

    /** MCP 自訂：標頭與 body 對不起來。 */
    private const int ERR_HEADER_MISMATCH = -32020;

    /**
     * 方案沒有開通這個功能。
     *
     * -32001 落在 JSON-RPC 保留給實作自訂的區間（-32000 ~ -32099）——這不是協定
     * 層級的錯誤，是我們的商業規則，不該借用規格定義好的那些碼。
     */
    private const int ERR_PLAN_REQUIRED = -32001;

    public function __construct(
        private McpService $mcp,
    ) {
    }

    public function __invoke(Request $request): ResponseInterface
    {
        // 用 all() 而不是自己 json_decode 原始 body：框架已經解析過一次，再讀一次
        // 串流會拿到空字串（游標在尾端），每個請求都會變成 parse error。
        $body = $request->all();

        if ($body === []) {
            return $this->error(null, self::ERR_PARSE, 'Parse error', 400);
        }

        // JSON-RPC 的 id 缺席代表這是 notification：不回結果，只回 202。
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);

        if ($method === '') {
            return $this->error($id, self::ERR_INVALID_REQUEST, 'Missing method', 400);
        }

        // 標頭與 body 不一致時拒絕。中介層（負載平衡、閘道）會照標頭路由，而
        // 伺服器照 body 執行——兩邊對不起來就是一個可以被利用的縫。標頭沒帶則
        // 不強制，舊版客戶端不會送。
        if ($mismatch = $this->headerMismatch($request, $method, $params)) {
            return $this->error($id, self::ERR_HEADER_MISMATCH, $mismatch, 400);
        }

        if ($unsupported = $this->unsupportedVersion($request)) {
            return $this->error($id, self::ERR_INVALID_REQUEST, $unsupported, 400, [
                'supported' => self::PROTOCOL_VERSIONS,
            ]);
        }

        if ($id === null) {
            // notification：規格要求「202 且沒有 body」。這裡不能用 json(null)——
            // 那會送出一個 "null" 字串當 body，而且型別上也不合。
            return response()->make('')->withStatus(202);
        }

        /** @var User $user */
        $user = $request->user();

        // **每一次請求都要檢查方案**，不是只在產生金鑰時檢查：金鑰不會過期，
        // 但方案會。降級或到期之後那把金鑰就該停止讀得到資料。
        //
        // 訊息寫得完整一點：這串字會直接顯示在對方的 AI 工具裡，使用者看到時
        // 通常不在 RSSPilot 的畫面上，沒有別的線索可循。
        if (!$this->planAllowsMcp($request)) {
            return $this->error(
                $id,
                self::ERR_PLAN_REQUIRED,
                'This RSSPilot plan does not include MCP access. Upgrade to Pro or above at '
                . 'https://rsspilot.app/upgrade to connect your data to AI tools.',
                403
            );
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping'       => $this->result($id, (object) []),
                'tools/list' => $this->result($id, ['tools' => $this->mcp->tools()]),
                'tools/call' => $this->result($id, $this->callTool($user, $params)),
                default      => $this->error($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$method}", 404),
            };
        } catch (InvalidArgumentException $e) {
            return $this->error($id, self::ERR_INVALID_PARAMS, $e->getMessage(), 200);
        } catch (Throwable $e) {
            // 細節不外流：這個端點的呼叫方是第三方工具，錯誤訊息會被原樣顯示給
            // 使用者看，甚至送進模型的 context。
            Log::error('mcp tool failed', [
                'method' => $method,
                'tool'   => $params['name'] ?? null,
                'error'  => $e->getMessage(),
            ]);

            return $this->error($id, self::ERR_INTERNAL, 'Internal error', 200);
        }
    }

    /**
     * 舊世代的握手。
     *
     * 只宣告 tools——我們沒有 resources 也沒有 prompts，宣告了卻回空清單只會讓
     * 客戶端多打兩次無用的請求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true)
                ? $requested
                : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => (object) []],
            'serverInfo'   => [
                'name'    => 'rsspilot',
                'title'   => 'RSSPilot',
                'version' => (string) config('app.version', '1.0.0'),
            ],
            'instructions' => 'RSSPilot holds this user\'s subscribed YouTube channels, their recent '
                . 'videos, and AI-generated summaries. Use list_sources / list_videos / search_videos to '
                . 'locate a video, then get_summary to read it.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(User $user, array $params): array
    {
        $name = (string) ($params['name'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('Missing tool name');
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => $this->mcp->call($user, $name, (array) ($params['arguments'] ?? []))],
            ],
            'isError' => false,
        ];
    }

    /**
     * `Mcp-Method` / `Mcp-Name` 標頭與 body 是否對得起來，對得起來回 null。
     *
     * `Mcp-Name` 允許 base64 哨兵格式（非 ASCII 的工具名稱用），比對前要先解碼。
     *
     * @param array<string, mixed> $params
     */
    private function headerMismatch(Request $request, string $method, array $params): ?string
    {
        $headerMethod = $request->header('Mcp-Method');

        if ($headerMethod !== null && $headerMethod !== '' && $headerMethod !== $method) {
            return "Mcp-Method header '{$headerMethod}' does not match body method '{$method}'";
        }

        $headerName = $request->header('Mcp-Name');

        if ($headerName === null || $headerName === '') {
            return null;
        }

        $headerName = $this->decodeHeaderValue($headerName);
        $bodyName = (string) ($params['name'] ?? $params['uri'] ?? '');

        return $bodyName !== '' && $headerName !== $bodyName
            ? "Mcp-Name header '{$headerName}' does not match body value '{$bodyName}'"
            : null;
    }

    /** `=?base64?...?=` 哨兵格式的標頭值。 */
    private function decodeHeaderValue(string $value): string
    {
        if (!str_starts_with($value, '=?base64?') || !str_ends_with($value, '?=')) {
            return $value;
        }

        $decoded = base64_decode(substr($value, 9, -2), true);

        return $decoded === false ? $value : $decoded;
    }

    /** 客戶端要求了我們沒實作的協定版本時回一句說明，否則 null。 */
    private function unsupportedVersion(Request $request): ?string
    {
        $version = $request->header('MCP-Protocol-Version');

        if ($version === null || $version === '' || in_array($version, self::PROTOCOL_VERSIONS, true)) {
            return null;
        }

        return "Unsupported protocol version: {$version}";
    }

    /** @param array<string, mixed>|object $result */
    private function result(mixed $id, array|object $result): ResponseInterface
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * 這位使用者的方案有沒有開通 MCP。
     *
     * 判準是 `plans.mcp_enabled`（目前 Pro 以上），與其他付費功能同一套做法：
     * 權益寫在資料上，不寫死方案名稱。沒有方案時一併關掉。
     */
    private function planAllowsMcp(Request $request): bool
    {
        return (bool) $this->userPlan($request)?->getAttribute('mcp_enabled');
    }

    /** @param array<string, mixed> $data */
    private function error(mixed $id, int $code, string $message, int $status, array $data = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error])->withStatus($status);
    }
}
