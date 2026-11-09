<?php
declare(strict_types=1);

namespace Furan\FormBuilder\Model\Email;

class QuotaAddress
{
    /** local+tag@domain and local@domain share one quota, so tags cannot multiply the mails to one inbox. */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a pure string helper shared by two actions
    public static function of(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');

        return ($plus === false ? $local : substr($local, 0, $plus)) . substr($email, $at);
    }
}
