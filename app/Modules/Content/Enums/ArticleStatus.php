<?php

namespace App\Modules\Content\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
