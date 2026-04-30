# Validation Rules 速查

Hypervel 的驗證規則與 Laravel 相同。以下為常用規則整理。

## 字串

| Rule | 說明 |
|------|------|
| `required` | 必填，不可為空字串 |
| `string` | 必須為字串型態 |
| `min:N` | 最小長度 N（字元數） |
| `max:N` | 最大長度 N（字元數） |
| `email` | 合法 Email 格式 |
| `url` | 合法 URL 格式 |
| `alpha` | 只含字母 |
| `alpha_num` | 只含字母與數字 |
| `regex:/pattern/` | 自訂正規表達式 |

## 數值

| Rule | 說明 |
|------|------|
| `integer` | 整數 |
| `numeric` | 數值（含浮點） |
| `min:N` | 最小值 N |
| `max:N` | 最大值 N |
| `between:N,M` | 介於 N 到 M 之間 |
| `gt:field` | 大於另一欄位值 |
| `gte:field` | 大於等於另一欄位值 |

## 陣列與物件

| Rule | 說明 |
|------|------|
| `array` | 必須為陣列 |
| `array:key1,key2` | 陣列且只允許指定 key |
| `size:N` | 陣列長度 / 字串長度 / 檔案大小等於 N |

## 存在性

| Rule | 說明 |
|------|------|
| `required` | 必填 |
| `nullable` | 可為 null（通常與其他 rule 搭配） |
| `sometimes` | 只有欄位存在時才驗證（用於 update / PATCH） |
| `required_if:field,value` | 當 `field` 等於 `value` 時必填 |
| `required_with:field,...` | 當指定欄位任一存在時必填 |
| `required_without:field,...` | 當指定欄位任一不存在時必填 |

## 確認欄位

| Rule | 說明 |
|------|------|
| `confirmed` | 需有 `{field}_confirmation` 欄位且值相同 |
| `same:field` | 值需與 `field` 相同 |
| `different:field` | 值需與 `field` 不同 |

## 資料庫

| Rule | 說明 |
|------|------|
| `exists:table,column` | 值必須存在於指定資料表欄位 |
| `unique:table,column` | 值在指定資料表欄位中必須唯一 |
| `unique:table,column,ignore_id` | 忽略指定 ID 的唯一性檢查（用於 update） |

## 日期時間

| Rule | 說明 |
|------|------|
| `date` | 合法日期格式 |
| `date_format:Y-m-d` | 符合指定格式的日期 |
| `before:date` | 早於指定日期 |
| `after:date` | 晚於指定日期 |

## 列舉

| Rule | 說明 |
|------|------|
| `in:val1,val2,...` | 值必須在清單中 |
| `not_in:val1,val2,...` | 值不可在清單中 |
| `enum:App\Enum\Status` | 值必須是該 PHP Enum 的合法 case |

## 常用組合

```php
// Email + 長度限制
'email' => 'required|email|max:191'

// 密碼 + 確認欄位
'password' => 'required|string|min:8|confirmed'

// 可選字串（PATCH 用）
'title' => 'sometimes|string|max:191'

// 可為空的文字
'description' => 'nullable|string|max:2000'

// 正整數
'price' => 'required|integer|min:0'

// 資料庫唯一（新增）
'account' => 'required|email|unique:users,account'

// 資料庫唯一（更新，忽略自身）
'account' => 'sometimes|email|unique:users,account,' . $id
```

## messages() Key 格式

```php
protected function messages(): array
{
    return [
        // '{field}.{rule}' => '訊息'
        'email.required'   => '請輸入 Email',
        'email.email'      => 'Email 格式不正確',
        'password.min'     => '密碼至少 8 碼',
        'password.confirmed' => '密碼確認不一致',
    ];
}
```
