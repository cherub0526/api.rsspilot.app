# 模板系統 - 多形設計 (Polymorphic Template System)

## 概述

這套模板系統使用多形設計模式，提供靈活且可擴展的方式來管理不同類型的 OpenAI 提示詞模板。系統設計遵循 SOLID
原則，支持多形性、工廠模式和策略模式。

## 系統架構

```
TemplateInterface (介面)
      ↑
      ├── BaseTemplate (抽象類別)
      │   ├── AssistantTemplate
      │   ├── SummaryTemplate
      │   ├── TranslationTemplate
      │   ├── CaptionTemplate
      │   └── AnalysisTemplate
      │
      └── TemplateFactory (工廠)
          └── TemplateCompletionManager (管理器)
```

## 核心元件

### 1. TemplateInterface

定義所有模板必須實現的介面：

- `getSystemPrompt()` - 系統提示詞
- `getUserPrompt()` - 使用者提示詞
- `getType()` - 模板類型
- `getParameters()` - 模板參數
- `buildMessages()` - 建立 OpenAI API 消息陣列

### 2. BaseTemplate (抽象類別)

提供基礎實現：

- 參數管理
- 消息構建邏輯
- 預設行為

### 3. 具體模板類別

#### AssistantTemplate

用於一般助手任務：

```php
$template = TemplateFactory::create('assistant', [
    'user_prompt' => 'Your custom prompt'
]);
```

#### SummaryTemplate

用於文本摘要和總結：

```php
$template = TemplateFactory::create('summary', [
    'length' => 'short',      // short, medium, long
    'language' => 'Traditional Chinese'
]);
```

#### TranslationTemplate

用於文本翻譯：

```php
$template = TemplateFactory::create('translation', [
    'source_language' => 'English',
    'target_language' => 'Traditional Chinese'
]);
```

#### CaptionTemplate

用於視頻字幕生成：

```php
$template = TemplateFactory::create('caption', [
    'language' => 'Traditional Chinese',
    'style' => 'professional'  // neutral, professional, casual
]);
```

#### AnalysisTemplate

用於內容分析：

```php
$template = TemplateFactory::create('analysis', [
    'analysis_type' => 'sentiment',  // general, sentiment, technical, etc.
    'depth' => 'moderate'            // shallow, moderate, deep
]);
```

#### FollowUpQuestionsTemplate

用於根據上一輪問答產生 3 個延伸問題：

```php
$template = TemplateFactory::create('follow_up_questions', [
    'user_question'   => 'What is a case interview?',
    'previous_answer' => 'A case interview is ...',
]);
```

### 4. TemplateFactory

工廠類別，負責建立和管理模板：

```php
// 建立模板
$template = TemplateFactory::create('summary', [...]);

// 註冊自訂模板
TemplateFactory::register('custom', CustomTemplate::class);

// 取得可用類型
$types = TemplateFactory::getAvailableTypes();

// 檢查類型是否存在
if (TemplateFactory::has('summary')) { ... }
```

### 5. TemplateCompletionManager

整合 Completion 服務和模板系統：

```php
$completion = new Completion($apiKey);
$template = TemplateFactory::create('summary', [...]);
$manager = new TemplateCompletionManager($completion, $template);

// 執行完成請求
$response = $manager->complete($userContent);

// 取得內容
$content = $manager->completeAndGetContent($userContent);
```

## 多形性特性

### 1. 使用相同介面處理不同類型

```php
$templates = [
    TemplateFactory::create('assistant', []),
    TemplateFactory::create('summary', [...]),
    TemplateFactory::create('translation', [...]),
];

foreach ($templates as $template) {
    $messages = $template->buildMessages($content);
}
```

### 2. 切換模板而不更改代碼

```php
$manager = new TemplateCompletionManager($completion, $template1);

// 切換模板
$manager->setTemplate($template2);

// 使用同樣的代碼，不同的行為
$result = $manager->complete($content);
```

### 3. 輕鬆擴展新模板

```php
class EmailTemplate extends BaseTemplate {
    protected string $type = 'email';
    
    public function getSystemPrompt(): string {
        return 'You are an email writing assistant...';
    }
    
    public function getUserPrompt(): string {
        return $this->parameters['email_prompt'] ?? '';
    }
}

TemplateFactory::register('email', EmailTemplate::class);
```

## 使用範例

### 基本使用

```php
// 1. 建立模板
$template = TemplateFactory::create('summary', [
    'length' => 'short',
    'language' => 'Traditional Chinese'
]);

// 2. 建立管理器
$completion = new Completion(env('OPENAI_API_KEY'));
$manager = new TemplateCompletionManager($completion, $template);

// 3. 執行完成
$content = $manager->completeAndGetContent('Your content here...');
```

### 進階使用

```php
$manager->setOptions([
    'temperature' => 0.5,
    'max_tokens' => 4000,
    'top_p' => 0.9
]);

$response = $manager->complete(
    userContent: 'Your content...',
    model: 'gpt-4',
    additionalParams: ['stream' => false]
);
```

### 多形處理

```php
function processWithTemplate(TemplateInterface $template, string $content) {
    // 無論是哪種模板，都可以相同的方式處理
    $messages = $template->buildMessages($content);
    return $messages;
}

// 任何實現 TemplateInterface 的類別都可以使用
processWithTemplate($summaryTemplate, $text);
processWithTemplate($translationTemplate, $text);
processWithTemplate($captionTemplate, $text);
```

## 設計優勢

1. **開放-關閉原則**: 對擴展開放，對修改關閉
    - 新增模板無需修改現有代碼

2. **里氏替換原則**: 所有子類別都可替代父類別
    - 多形性使得不同模板可互換使用

3. **單一職責原則**: 每個類別職責單一
    - `TemplateInterface`: 定義介面
    - `BaseTemplate`: 提供通用實現
    - 具體類別: 特定類型的模板
    - `TemplateFactory`: 物件建立
    - `TemplateCompletionManager`: 與服務整合

4. **依賴反轉**: 依賴抽象而非具體實現
    - 代碼依賴 `TemplateInterface` 而非具體類別

5. **易於測試**: 可以輕鬆 mock 和測試
    - 使用同一介面進行單元測試

## 檔案結構

```
app/Services/Prompts/
├── TemplateInterface.php           # 模板介面
├── BaseTemplate.php                # 基礎模板
├── AssistantTemplate.php           # 助手模板
├── SummaryTemplate.php             # 摘要模板
├── TranslationTemplate.php         # 翻譯模板
├── CaptionTemplate.php             # 字幕模板
├── AnalysisTemplate.php            # 分析模板
├── TemplateFactory.php             # 工廠類別
├── TemplateCompletionManager.php   # 管理器
├── TemplateUsageExample.php        # 使用範例
└── README.md                       # 本文檔
```

## 擴展指南

### 建立自訂模板

```php
<?php

namespace App\Services\Prompts;

class MyCustomTemplate extends BaseTemplate
{
    protected string $type = 'my_custom';

    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Your system instruction here...
PROMPT;
    }

    public function getUserPrompt(): string
    {
        return $this->parameters['user_prompt'] ?? '';
    }
}
```

### 註冊自訂模板

```php
// 在應用啟動時 (如 ServiceProvider)
TemplateFactory::register('my_custom', MyCustomTemplate::class);

// 使用
$template = TemplateFactory::create('my_custom', [
    'user_prompt' => 'Your prompt here'
]);
```

## 最佳實踐

1. **使用工廠建立模板**: 不要直接 `new TemplateClass()`
2. **透過介面編程**: 傳入 `TemplateInterface` 而非具體類別
3. **設定合適的 OpenAI 參數**: 針對不同任務調整 temperature 和 max_tokens
4. **參數驗證**: 在模板建立前驗證參數
5. **異常處理**: 適當處理 API 調用的異常

## 常見問題

**Q: 如何建立新的模板類型?**
A: 繼承 `BaseTemplate` 並實現 `getSystemPrompt()` 和 `getUserPrompt()` 方法，然後使用 `TemplateFactory::register()` 註冊。

**Q: 可以在運行時切換模板嗎?**
A: 可以。使用 `TemplateCompletionManager::setTemplate()` 方法。

**Q: 如何傳入自訂參數?**
A: 通過 `TemplateFactory::create()` 的第二個參數傳入陣列，參數會被保存在 `$this->parameters` 中。

**Q: 支持多語言嗎?**
A: 支持。將語言設定作為參數傳入，在 `getSystemPrompt()` 中使用。

---

## 版本

v1.0.0 - 初始發布

## 授權

MIT

