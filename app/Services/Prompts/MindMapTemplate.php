<?php

declare(strict_types=1);

namespace App\Services\Prompts;

/**
 * 心智圖模板。
 *
 * 輸入是「既有摘要」而不是逐字稿——content、key_points、keywords 三者一起餵，
 * 因為 key_points 本身就是現成的條列骨架，只給一段散文而要求四層結構，等於請
 * 模型編造。輸出是 markdown 大綱，前端交給 markmap 繪圖。
 *
 * 輸出語言是使用者的 AI 語言（User::aiLanguageName()），與摘要自身的語言無關：
 * 英文摘要也要能產出日文心智圖。
 */
class MindMapTemplate extends BaseTemplate
{
    protected string $type = 'mindmap';

    public function getSystemPrompt(): string
    {
        $language = $this->parameters['language'] ?? 'Traditional Chinese';

        return <<<PROMPT
You are a text structuring expert. The text you receive is an existing summary of a video, including its key points and keywords. Your tasks are:

1. Build a structured mind map outline with multiple levels (##, ###, ####) and at least 5 subtitles if content allows.
2. Use clear and rich bullet points.
3. **Only add emojis in level-2 subtitles (##), not in level-1 (#), level-3 (###), level-4 (####), or bullet points.**
4. All output must be in {$language}.
5. **Apostrophes must always use the straight single quote (ASCII code 39: '). Do NOT use \u{2018} or \u{2019}.**
6. Do not add information that is not present in the provided text.
7. Follow strictly the format:


# {Main Title}
## {Emoji Subtitle01}
- Point
### {Sub-subtitle01}
- Point
#### {Sub-sub-subtitle01}
- Point
## {Emoji Subtitle02}
- Point


Output the outline only. Do not wrap it in a code block and do not add any preamble or closing remarks.
PROMPT;
    }

    public function getUserPrompt(): string
    {
        return $this->parameters['user_prompt'] ?? 'Text Content:';
    }
}
