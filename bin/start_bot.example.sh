#!/bin/sh

## REQUIRED: Get a slack token and put it here
export SLACK_TOKEN=

## REQUIRED: Point to your xymon instance
export XYMON_URL='http://xymon.example.com/xymon-cgimsg/xymoncgimsg.cgi'
export XYMON_WEB_URL='http://xymon.example.com/xymon-cgi/svcstatus.sh?HOST=%s&SERVICE=%s'
export XYMON_HOST_URL='http://xymon.example.com/xymon/%s/%s.html'

## OPTIONAL: Comma separated list of test(s) to not show on nongreen list
# export XYMON_SET_NOPROPRED='cpu'

exec php $(dirname $0)/bot.php;
