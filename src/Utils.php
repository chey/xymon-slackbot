<?php
namespace XymonSlack;

final class Utils
{
    /**
     * @var array
     */
    const COLOR_MAP = ['red' => 'danger', 'yellow' => 'warning', 'green' => 'good', 'blue' => '#0048FF', 'purple' => '#800080'];

    /**
     * Supply a hostname.testname combination and get back an array.
     *
     * @param string $hosttest
     * @return array with hostname first then testname
     */
    public static function splitHost($hosttest)
    {
        $hostname = $testname = null;
        if (false !== strpos($hosttest, ',')) {
            list($hostname, $testname) = array_merge(explode(',', $hosttest), array(null));
        } else {
            $hostname = substr($hosttest, 0, strrpos($hosttest, '.'));
            $testname = substr($hosttest, strrpos($hosttest, '.') + 1);
        }

        return [$hostname, $testname];
    }

    /**
     * $param string $text
     * @return string
     */
    public static function unfurl($text)
    {
        $text = preg_replace('/<@\w+>/', '', $text);
        $text = preg_replace('/<(.+)\|(.+)>/', '$2', $text);
        return trim($text);
    }

    public static function slackColor($color)
    {
        $slackColor = null;

        if (array_key_exists($color, self::COLOR_MAP)) {
            $slackColor = self::COLOR_MAP[$color];
        }

        return $slackColor;
    }
}
