<?php

if (!function_exists('header_log')) {
    /**
     * Very dumb way to debug using HTTP headers
     *
     * @param   $str string  Message to be written in HTTP header
     * @returns         void
     *
     */
    function header_log($str)
    {
        static $c = -1; $c++;

        @header("X-Debug-Log---------------------------------------------[{$c}]: ". json_encode($str,1));
    }
}
