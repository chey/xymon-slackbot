<?php
namespace XymonSlack\Test;

use XymonSlack\Utils;

class UtilsTest extends \PHPUnit_Framework_TestCase
{
    public function testSplitHost()
    {
        $ht = 'test-server-01.example.com.conn';
        list($hostname, $testname) = Utils::splitHost($ht);
        $this->assertEquals('test-server-01.example.com', $hostname);
        $this->assertEquals('conn', $testname);
    }

    public function testUnfurl()
    {
        $msg = '<@U532FD4R> ack <http://test.example.com|test.example.com>';
        $this->assertEquals('ack test.example.com', Utils::unfurl($msg));
    }

    public function testSlackColor()
    {
        $this->assertEquals('danger', Utils::slackColor('red'));
    }
}
