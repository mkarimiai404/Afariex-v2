<?php
declare(strict_types=1);

function remittance_destination_groups(): array
{
    return [
        'requested' => [
            'AF'=>'افغانستان','TR'=>'ترکیه','CA'=>'کانادا','GB'=>'بریتانیا','US'=>'ایالات متحده آمریکا',
            'CN'=>'چین','RU'=>'روسیه','PK'=>'پاکستان','TJ'=>'تاجیکستان','UZ'=>'ازبکستان','TM'=>'ترکمنستان','KZ'=>'قزاقستان',
        ],
        'europe' => [
            'AL'=>'آلبانی','AT'=>'اتریش','BE'=>'بلژیک','BA'=>'بوسنی و هرزگوین','BG'=>'بلغارستان','HR'=>'کرواسی','CY'=>'قبرس','CZ'=>'چک','DK'=>'دانمارک','EE'=>'استونی','FI'=>'فنلاند','FR'=>'فرانسه','DE'=>'آلمان','GR'=>'یونان','HU'=>'مجارستان','IS'=>'ایسلند','IE'=>'ایرلند','IT'=>'ایتالیا','LV'=>'لتونی','LT'=>'لیتوانی','LU'=>'لوکزامبورگ','MT'=>'مالت','MD'=>'مولداوی','ME'=>'مونته‌نگرو','NL'=>'هلند','MK'=>'مقدونیه شمالی','NO'=>'نروژ','PL'=>'لهستان','PT'=>'پرتغال','RO'=>'رومانی','RS'=>'صربستان','SK'=>'اسلواکی','SI'=>'اسلوونی','ES'=>'اسپانیا','SE'=>'سوئد','CH'=>'سوئیس','UA'=>'اوکراین',
        ],
        'arab' => [
            'DZ'=>'الجزایر','BH'=>'بحرین','KM'=>'کومور','DJ'=>'جیبوتی','EG'=>'مصر','IQ'=>'عراق','JO'=>'اردن','KW'=>'کویت','LB'=>'لبنان','LY'=>'لیبی','MR'=>'موریتانی','MA'=>'مراکش','OM'=>'عمان','PS'=>'فلسطین','QA'=>'قطر','SA'=>'عربستان سعودی','SO'=>'سومالی','SD'=>'سودان','SY'=>'سوریه','TN'=>'تونس','AE'=>'امارات متحده عربی','YE'=>'یمن',
        ],
    ];
}

function remittance_destination_countries(): array
{
    return array_merge(...array_values(remittance_destination_groups()));
}

function remittance_destination_country_is_supported(string $countryCode): bool
{
    return array_key_exists(strtoupper(trim($countryCode)), remittance_destination_countries());
}
