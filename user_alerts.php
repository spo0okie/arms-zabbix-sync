<?php
/**
* @var $zabbixApiUrl string
* @var $zabbixAuth string
*/

include dirname(__FILE__).'/config.priv.php';
require_once dirname(__FILE__).'/lib_zabbixApi.php';
require_once dirname(__FILE__).'/lib_arrHelper.php';

$zabbix=new zabbixApi();
$zabbix->init($zabbixApiUrl,$zabbixAuth,[]);

$login='kotov.n';

$user=$zabbix->searchUserByLogin($login)[0]??[];
//var_dump($user);
if (!$user['userid']??false) die("User $login not found");

$username=$user['surname'];
if (!$user['surname']??false) die("User $login has empty surname!");


//mediatypeid:
// 0 - email
// 6 - sms скрипт
// 8 - telegram
$mail_message=new stdClass();
$mail_message->mediatypeid=1;

$sms_message=new stdClass();
$sms_message->mediatypeid=6;


function formOperation($step_len,$step,$message,$user) {
    $addr_user=new stdClass();
    $addr_user->userid=$user['userid'];

    $operation=new stdClass();
    $operation->operationtype=0;   //0 - "send message".
    $operation->esc_step_from=$step;   //60m
    $operation->esc_step_to=$step;     //60m
    $operation->opmessage=$message;
    $operation->opmessage_usr=[$addr_user];
    $operation->esc_period=$step_len;//Default operation step duration.

    return $operation;
}

//https://www.zabbix.com/documentation/current/en/manual/api/reference/action/object#action-operation
$operation_mail_10m= formOperation('10m',2,$mail_message,$user);
$operation_mail_120m=formOperation('10m',13,$mail_message,$user);
$operation_sms_15m=  formOperation('15m',2,$sms_message,$user);
$operation_sms_60m=  formOperation('10m',7,$sms_message,$user);

//$operation_mail_60m



//https://www.zabbix.com/documentation/current/en/manual/api/reference/action/object#action-filter-condition
/* Type of condition.

Possible values if eventsource of Action object is set to "event created by a trigger":
0 - host group;
1 - host;
2 - trigger;
3 - trigger name;
4 - trigger severity;
6 - time period;
13 - host template;
16 - problem is suppressed;
25 - event tag;
26 - event tag value.

Possible values if eventsource of Action object is set to "event created by a discovery rule":
7 - host IP;
8 - discovered service type;
9 - discovered service port;
10 - discovery status;
11 - uptime or downtime duration;
12 - received value;
18 - discovery rule;
19 - discovery check;
20 - proxy;
21 - discovery object.

Possible values if eventsource of Action object is set to "event created by active agent autoregistration":
20 - proxy;
22 - host name;
24 - host metadata.

Possible values if eventsource of Action object is set to "internal event":
0 - host group;
1 - host;
13 - host template;
23 - event type;
25 - event tag;
26 - event tag value.

Possible values if eventsource of Action object is set to "event created on service status update":
25 - event tag;
26 - event tag value;
27 - service;
28 - service name.
 */

/* Severity of the trigger.

Possible values:
0 - (default) not classified;
1 - information;
2 - warning;
3 - average;
4 - high;
5 - disaster.
 */

/* Condition operator.

Possible values:
0 - (default) equals;
1 - does not equal;
2 - contains;
3 - does not contain;
4 - in;
5 - is greater than or equals;
6 - is less than or equals;
7 - not in;
8 - matches;
9 - does not match;
10 - Yes;
11 - No.
*/

//problem is not suppressed
$condition['A']=new stdClass();
$condition['A']->conditiontype=16; //16 - problem is suppressed;
$condition['A']->operator=11;      //11 - No
$condition['A']->formulaid='A';

$notSuppressed='A';

//severity is disaster
$condition['B']=new stdClass();
$condition['B']->conditiontype=4;  //4 - trigger severity;
$condition['B']->value=5;          //5 - disaster.
$condition['B']->operator=0;       //0 - equals
$condition['B']->formulaid='B';

$notDisaster='B';

//severity is NOT disaster
$condition['C']=new stdClass();
$condition['C']->conditiontype=4;  //4 - trigger severity;
$condition['C']->value=5;          //5 - disaster.
$condition['C']->operator=1;       //1 - does not equal;
$condition['C']->formulaid='C';

$isDisaster='C';

//Tag does not equal supportteam
$condition['D']=new stdClass();
$condition['D']->conditiontype=25; //25 - event tag;
$condition['D']->value='supportteam';
$condition['D']->operator=1;       //1 - does not equal;
$condition['D']->formulaid='D';

$noSupportTeam='D';

//Value of tag supportteam contains Акаев
$condition['E']=new stdClass();
$condition['E']->conditiontype=26; //26 - event tag value;
$condition['E']->value2='supportteam';
$condition['E']->value=$username;
$condition['E']->operator=2;       //contains
$condition['E']->formulaid='E';

$isSupportTeam='E';

//Value of tag node-support contains Акаев
$condition['F']=new stdClass();
$condition['F']->conditiontype=26; //26 - event tag value;
$condition['F']->value2='node-support';
$condition['F']->value=$username;
$condition['F']->operator=2;       //contains
$condition['F']->formulaid='F';

$isNodeSupport='(D and F)';

//Tag does not equal serviceman
$condition['G']=new stdClass();
$condition['G']->conditiontype=25; //25 - event tag;
$condition['G']->value='serviceman';
$condition['G']->operator=1;       //1 - does not equal;
$condition['G']->formulaid='G';

$noServiceMan='G';

//Value of tag serviceman contains Акаев
$condition['H']=new stdClass();
$condition['H']->conditiontype=26; //26 - event tag value;
$condition['H']->value2='serviceman';
$condition['H']->value=$username;
$condition['H']->operator=2;       //contains
$condition['H']->formulaid='H';

$isServiceMan='H';

//Value of tag node-service contains Акаев
$condition['I']=new stdClass();
$condition['I']->conditiontype=26; //26 - event tag value;
$condition['I']->value2='node-service';
$condition['I']->value=$username;
$condition['I']->operator=2;       //contains
$condition['I']->formulaid='I';

$isNodeService='I';

//Value of tag alert contains Акаев
$condition['J']=new stdClass();
$condition['J']->conditiontype=26; //26 - event tag value;
$condition['J']->value2='alert';
$condition['J']->value=$username;
$condition['J']->operator=2;       //contains
$condition['J']->formulaid='J';

$isAlert='J';

//Value of tag alert contains смсАкаев
$condition['K']=new stdClass();
$condition['K']->conditiontype=26; //26 - event tag value;
$condition['K']->value2='alert';
$condition['K']->value="смс$username";
$condition['K']->operator=2;       //contains
$condition['K']->formulaid='K';

$isSmsAlert='K';

function fetchActionId($name) {
    global $zabbix;
    $exist=$zabbix->searchActionByName($name);
    if (!count($exist)) {
        return null;
        //die ("$name not found");
    }
    return $exist[0]['actionid'];
}

function formCondition($formula) {
    global $condition;
    //https://www.zabbix.com/documentation/current/en/manual/api/reference/action/object#action-filter
    $filter=new stdClass();
    $filter->evaltype=3;//3 - custom expression.
    $filter->formula=$formula;
    $filter->conditions=[];
    $letters=preg_replace('/[^A-Z]+/','',$formula);
    foreach (str_split($letters) as $letter)
        $filter->conditions[]=$condition[$letter];
    return $filter;
}

function formAction($name,$operation,$formula) {
    $action=new stdClass();
    $action->name=$name;
    $action->eventsource=0;   //0 - event created by a trigger;
    $action->notify_if_canceled=0;
    $action->filter=formCondition($formula);
    $action->operations=[$operation];
    if ($actionid=fetchActionId($name))
    $action->actionid=$actionid;
    return $action;
}



$except_sms=formAction(
    "$username - кроме смс",
    $operation_mail_10m,
    "$notSuppressed and ($isServiceMan or $isNodeService or $isAlert)"
);

$tp_except_sms=formAction(
    "$username - ТП кроме смс",
    $operation_mail_120m,
    "$notSuppressed and ($isSupportTeam or $isNodeSupport)"
);

$sms=formAction(
    "$username - смс",
    $operation_sms_15m,
    "($notSuppressed and $isDisaster) and ($isServiceMan or $isNodeService or $isSmsAlert)"
);

$tp_sms=formAction(
    "$username - ТП смс",
    $operation_sms_60m,
    "($notSuppressed and $isDisaster) and ($isSupportTeam or $isNodeSupport)"
);

function pushAction($action) {
    global $zabbix;
    if ($action->actionid??false)
        $zabbix->req('action.update',$action);
    else
        $zabbix->req('action.create',$action);
}

pushAction($except_sms);
pushAction($tp_except_sms);
pushAction($sms);
pushAction($tp_sms);
