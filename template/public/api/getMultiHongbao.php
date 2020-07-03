<?php
// 看完广告后, 领取多倍红包

// 公用方法
include("../../comm/comm.php");
// 数据库操作
include("../../extra/DBOperator.php");
// 系统配置
include("../../extra/sysConfig.php");
// 通用错误码
include("../../extra/error.php");
// 消息ID
include("../../extra/message.php");

// 逻辑管理器
include("../../manager/configManager.php");
include("../../manager/playerDataSync.php");
include("../../manager/roleManager.php");


include("../../manager/petManager.php");

// 接收参数
$parameter = null;
$tempContent = file_get_contents("php://input");
if (!empty($tempContent)){
	// 从流中获取数据
	$parameter = json_decode($tempContent, true);
}else if (!empty($_POST)){
	// 一般的表单提交可以直接同过$_POST来获取传参
	$parameter = $_POST;
}

$msgID = $MESSAGE['GET_MULTI_HONGBAO'];
$uid  = number_format($parameter['uid'],0, '', ''); // uid
$handler = $parameter['handler']; // 获取红包的渠道
$param = @$parameter['param']?json_decode($parameter['param'],true):[]; // 额外的参数
$multi = @$parameter['multi']?$parameter['multi']:1;

if ( !is_numeric($multi) || $multi > 3 || $multi < 1 ) {
	$multi = 1;
}else{
	$multi = (int)$multi;
}

if ( empty($param) ){
	$param = [];
}

// 初始化连接（保证一次请求只取一次连接）
$initConnRet = DB_operator_initConnRedis();
if ( empty($initConnRet) || $initConnRet['code'] != 0 ){
	return RstMsg(error_getCommonError('DB_CONN_FAIL'), $msgID);
}

$connObj = $initConnRet['data'];
// 根据这个账号从数据库中查找数据
$getRoleDataRet = DB_operator_getRoleDataNonLogin($uid, $connObj->redisConn);

if ( empty($getRoleDataRet) || $getRoleDataRet['code'] != 0 ){
	// 只有内部出错，才会拿不到返回的数据
	return RstMsg(error_getCommonError('GET_USER_DATA_FAIL'), $msgID);
}

$player = json_decode($getRoleDataRet['data']);

if ( empty($player) ){
	return RstMsg(error_getCommonError('USER_DATA_IS_NULL'), $msgID);
}

// 日重置效验
roleManager_checkDayReset($player, true);

// 参数效验
if (!roleManager_getHongbaoParamsCheck($player, $handler, $param)){
	return RstMsg(error_getCommonError('GET_EXTRA_HB_FAILURE'), $msgID);
};

// 做客户端的一些数据同步
roleManager_syncData($player, [
	"money" => @$parameter['syncMoney']?(int)$parameter['syncMoney'] : 0 ,
	"speed" => @$parameter['syncSpeed']?(int)$parameter['syncSpeed'] : 0 ,
	"ad" => @$parameter['syncAD']?(int)$parameter['syncAD'] : 0
]);

// 奖励列表 --TODO 奖励列表主要是用来给前端做展示
$rewardList = [];

roleManager_getHongbaoDispose( $player, $handler, $param, $multi, $rewardList );

//  数据入库
$setRoleDataRet = DB_operator_setRoleDataByRedis($uid, $connObj->redisConn, json_encode($player) );
if ( empty($setRoleDataRet) || $setRoleDataRet['code'] != 0 ){
	return RstMsg(error_getCommonError('SET_USER_DATA_FAIL'), $msgID);
}

$messageRetData = new stdClass();
$messageRetData->update = playerDataSync_getUpdate();
$messageRetData->rewardList = $rewardList;
$messageRetData->handler = $handler;

return RstMsg(0, $msgID, $messageRetData);




