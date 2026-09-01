<?php

declare(strict_types=1);

namespace App\Utils\Const;

class ISO6391
{
    public const LANGUAGES = [
        'Abkhazian'           => 'ab',
        'Afar'                => 'aa',
        'Afrikaans'           => 'af',
        'Akan'                => 'ak',
        'Albanian'            => 'sq',
        'Amharic'             => 'am',
        'Arabic'              => 'ar',
        'Aragonese'           => 'an',
        'Armenian'            => 'hy',
        'Assamese'            => 'as',
        'Avaric'              => 'av',
        'Avestan'             => 'ae',
        'Aymara'              => 'ay',
        'Azerbaijani'         => 'az',
        'Bambara'             => 'bm',
        'Bashkir'             => 'ba',
        'Basque'              => 'eu',
        'Belarusian'          => 'be',
        'Bengali'             => 'bn',
        'Bislama'             => 'bi',
        'Bosnian'             => 'bs',
        'Breton'              => 'br',
        'Bulgarian'           => 'bg',
        'Burmese'             => 'my',
        'Catalan'             => 'ca',
        'Chamorro'            => 'ch',
        'Chechen'             => 'ce',
        'Chichewa'            => 'ny',
        'Chinese'             => 'zh',
        'Traditional Chinese' => 'zh-TW',
        'Simplified Chinese'  => 'zh-CN',
        'Chuvash'             => 'cv',
        'Cornish'             => 'kw',
        'Corsican'            => 'co',
        'Cree'                => 'cr',
        'Croatian'            => 'hr',
        'Czech'               => 'cs',
        'Danish'              => 'da',
        'Divehi'              => 'dv',
        'Dutch'               => 'nl',
        'Dzongkha'            => 'dz',
        'English'             => 'en',
        'Esperanto'           => 'eo',
        'Estonian'            => 'et',
        'Ewe'                 => 'ee',
        'Faroese'             => 'fo',
        'Fijian'              => 'fj',
        'Finnish'             => 'fi',
        'French'              => 'fr',
        'Fula'                => 'ff',
        'Galician'            => 'gl',
        'Ganda'               => 'lg',
        'Georgian'            => 'ka',
        'German'              => 'de',
        'Greek'               => 'el',
        'Guaraní'             => 'gn',
        'Gujarati'            => 'gu',
        'Haitian Creole'      => 'ht',
        'Hausa'               => 'ha',
        'Hebrew'              => 'he',
        'Herero'              => 'hz',
        'Hindi'               => 'hi',
        'Hiri Motu'           => 'ho',
        'Hungarian'           => 'hu',
        'Icelandic'           => 'is',
        'Ido'                 => 'io',
        'Igbo'                => 'ig',
        'Indonesian'          => 'id',
        'Interlingua'         => 'ia',
        'Interlingue'         => 'ie',
        'Inuktitut'           => 'iu',
        'Inupiaq'             => 'ik',
        'Irish'               => 'ga',
        'Italian'             => 'it',
        'Japanese'            => 'ja',
        'Javanese'            => 'jv',
        'Kalaallisut'         => 'kl',
        'Kannada'             => 'kn',
        'Kanuri'              => 'kr',
        'Kashmiri'            => 'ks',
        'Kazakh'              => 'kk',
        'Khmer'               => 'km',
        'Kikuyu'              => 'ki',
        'Kinyarwanda'         => 'rw',
        'Kirghiz'             => 'ky',
        'Komi'                => 'kv',
        'Kongo'               => 'kg',
        'Korean'              => 'ko',
        'Kurdish'             => 'ku',
        'Kwanyama'            => 'kj',
        'Lao'                 => 'lo',
        'Latin'               => 'la',
        'Latvian'             => 'lv',
        'Limburgish'          => 'li',
        'Lingala'             => 'ln',
        'Lithuanian'          => 'lt',
        'Luba-Katanga'        => 'lu',
        'Luxembourgish'       => 'lb',
        'Macedonian'          => 'mk',
        'Malagasy'            => 'mg',
        'Malay'               => 'ms',
        'Malayalam'           => 'ml',
        'Maltese'             => 'mt',
        'Manx'                => 'gv',
        'Maori'               => 'mi',
        'Marathi'             => 'mr',
        'Marshallese'         => 'mh',
        'Mongolian'           => 'mn',
        'Nauru'               => 'na',
        'Navajo'              => 'nv',
        'Ndebele, North'      => 'nd',
        'Ndebele, South'      => 'nr',
        'Ndonga'              => 'ng',
        'Nepali'              => 'ne',
        'Northern Sami'       => 'se',
        'Norwegian'           => 'no',
        'Norwegian Bokmål'    => 'nb',
        'Norwegian Nynorsk'   => 'nn',
        'Nuosu'               => 'ii',
        'Occitan'             => 'oc',
        'Ojibwa'              => 'oj',
        'Oriya'               => 'or',
        'Oromo'               => 'om',
        'Ossetian'            => 'os',
        'Pali'                => 'pi',
        'Pashto'              => 'ps',
        'Persian'             => 'fa',
        'Polish'              => 'pl',
        'Portuguese'          => 'pt',
        'Punjabi'             => 'pa',
        'Quechua'             => 'qu',
        'Romanian'            => 'ro',
        'Romansh'             => 'rm',
        'Rundi'               => 'rn',
        'Russian'             => 'ru',
        'Samoan'              => 'sm',
        'Sango'               => 'sg',
        'Sanskrit'            => 'sa',
        'Sardinian'           => 'sc',
        'Scottish Gaelic'     => 'gd',
        'Serbian'             => 'sr',
        'Shona'               => 'sn',
        'Sindhi'              => 'sd',
        'Sinhala'             => 'si',
        'Slovak'              => 'sk',
        'Slovenian'           => 'sl',
        'Somali'              => 'so',
        'Sotho, Southern'     => 'st',
        'Spanish'             => 'es',
        'Sundanese'           => 'su',
        'Swahili'             => 'sw',
        'Swati'               => 'ss',
        'Swedish'             => 'sv',
        'Tagalog'             => 'tl',
        'Tahitian'            => 'ty',
        'Tajik'               => 'tg',
        'Tamil'               => 'ta',
        'Tatar'               => 'tt',
        'Telugu'              => 'te',
        'Thai'                => 'th',
        'Tibetan'             => 'bo',
        'Tigrinya'            => 'ti',
        'Tonga'               => 'to',
        'Tsonga'              => 'ts',
        'Tswana'              => 'tn',
        'Turkish'             => 'tr',
        'Turkmen'             => 'tk',
        'Twi'                 => 'tw',
        'Uighur'              => 'ug',
        'Ukrainian'           => 'uk',
        'Urdu'                => 'ur',
        'Uzbek'               => 'uz',
        'Venda'               => 've',
        'Vietnamese'          => 'vi',
        'Volapük'             => 'vo',
        'Walloon'             => 'wa',
        'Welsh'               => 'cy',
        'Western Frisian'     => 'fy',
        'Wolof'               => 'wo',
        'Xhosa'               => 'xh',
        'Yiddish'             => 'yi',
        'Yoruba'              => 'yo',
        'Zhuang'              => 'za',
        'Zulu'                => 'zu',
    ];

    public static function getCodeByName($name): ?string
    {
        return self::LANGUAGES[$name] ?? null;
    }

    public static function getNameByCode($code): false|int|string
    {
        return array_search($code, self::LANGUAGES);
    }

    /**
     * 把各種寫法的語言代碼收斂成這張表使用的形式。
     *
     * 同一個語言在專案裡有兩種寫法：`settings.data.locale` 存的是這張表的
     * `zh-TW`（連字號、地區大寫），而字幕與摘要沿用 `Caption::LOCAL_ZH_TW`
     * 的 `zh_tw`（底線、全小寫）。兩者字面不相等，直接比對只有 `en` 這種沒有
     * 地區碼的會中——摘要要依使用者語系挑選就必須先過這一層。
     *
     * 查不到的代碼原樣回傳，不猜也不丟例外：這裡的角色是正規化，不是驗證。
     * 帶地區但整組查不到時（例如 `zh-HK`）退回語言本身（`zh`），因為地區
     * 變體對不上通常仍屬同一語言，比整個對不到有用。
     */
    public static function normalize(string $code): string
    {
        $parts = explode('-', str_replace('_', '-', trim($code)), 2);
        $language = strtolower($parts[0]);
        $candidate = isset($parts[1]) ? $language . '-' . strtoupper($parts[1]) : $language;

        if (in_array($candidate, self::LANGUAGES, true)) {
            return $candidate;
        }

        return in_array($language, self::LANGUAGES, true) ? $language : $code;
    }
}
