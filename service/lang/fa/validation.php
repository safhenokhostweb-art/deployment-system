<?php

/*
 * Laravel's own validation-message file, translated for the web (dashboard)
 * locale only. Rule names/keys and the file structure are Laravel's
 * standard format — nothing about how $request->validate() is called in
 * any Controller changes; this file only supplies the Persian strings
 * Laravel already knows how to look up once app()->getLocale() is 'fa'.
 */

return [

    'required' => 'فیلد :attribute الزامی است.',
    'string' => 'فیلد :attribute باید متن باشد.',
    'email' => 'فیلد :attribute باید یک ایمیل معتبر باشد.',
    'max' => [
        'string' => 'فیلد :attribute نباید بیشتر از :max کاراکتر باشد.',
        'numeric' => 'فیلد :attribute نباید بزرگتر از :max باشد.',
    ],
    'min' => [
        'string' => 'فیلد :attribute باید حداقل :min کاراکتر باشد.',
        'numeric' => 'فیلد :attribute باید حداقل :min باشد.',
    ],
    'size' => [
        'string' => 'فیلد :attribute باید دقیقاً :size کاراکتر باشد.',
        'numeric' => 'فیلد :attribute باید دقیقاً :size باشد.',
    ],
    'regex' => 'قالب فیلد :attribute نامعتبر است.',
    'integer' => 'فیلد :attribute باید عدد صحیح باشد.',
    'url' => 'فیلد :attribute باید یک آدرس معتبر باشد.',
    'boolean' => 'فیلد :attribute باید بله یا خیر باشد.',
    'exists' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',
    'unique' => 'این مقدار برای :attribute قبلاً استفاده شده است.',
    'in' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',

    'attributes' => [
        'name' => 'نام',
        'description' => 'توضیحات',
        'project_key' => 'کلید پروژه',
        'repository' => 'مخزن',
        'branch' => 'شاخه',
        'environment' => 'محیط',
        'server_id' => 'سرور',
        'pipeline' => 'پایپ‌لاین',
        'commit_hash' => 'کامیت',
        'url' => 'آدرس',
        'expected_status' => 'کد وضعیت مورد انتظار',
        'email' => 'ایمیل',
        'password' => 'رمز عبور',
    ],

    'custom' => [
        'project_key' => [
            'regex' => 'کلید پروژه باید فقط شامل حروف کوچک انگلیسی، اعداد و خط تیره باشد.',
        ],
        'repository' => [
            'regex' => 'مخزن باید به شکل owner/repo باشد.',
        ],
        'commit_hash' => [
            'regex' => 'کامیت باید یک SHA کامل و ۴۰ کاراکتری باشد.',
            'size' => 'کامیت باید دقیقاً ۴۰ کاراکتر باشد.',
        ],
    ],

];
