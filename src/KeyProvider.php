<?php

namespace OndraKoupil\Csob;
interface KeyProvider
{
    /**
     * @return string
     * @throw CryptoException
     */
    function getKey();

    /**
     * @return string
     */
    function __toString();
}
