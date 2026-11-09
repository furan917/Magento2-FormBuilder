<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Test\Unit\Helper;

use Furan\FormBuilder\Model\FieldType\Pool;
use Furan\FormBuilder\Model\I18n\TextCatalog;
use Furan\FormBuilder\Model\I18n\Translator;
use Furan\FormBuilder\Model\Url\RedirectValidator;

class TranslatorFactory
{
    public static function create(RedirectValidator $redirects, ?Pool $types = null): Translator
    {
        return new Translator(new TextCatalog($types ?? new Pool([])), $redirects);
    }
}
