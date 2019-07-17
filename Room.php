<?php
namespace app\admin\logic;

use app\admin\exception\BaseException;
use app\admin\exception\LogicException;
use app\admin\exception\ModelException;
use app\admin\helper\CommonCheck;
use app\admin\helper\redis_cache;
use base\Lists;
use think\Exception;
use think\Db;

class Room extends BaseLogic
{

    /*******************************基础配置开始*******************************/

    public $flag_opt                   = array();
    public $id_alias                   = "goods_id"; //主表与其他表关联的字段
    private $flag_model                = ""; //标记模型
    private $slave_model               = ""; //从表模型
    private $slave_relation_field      = ""; //从表和主表关联字段
    private $_privateRoomOnlineUpCache = 'privateRoomOnlineUpCache';
    private $_privateRoomOnlineCache   = 'privateRoomOnlineCache';
    private $_roomOnlineRoomCache      = 'roomOnlineRoomCache'; //存放用户id
    private $_roomOnlineRoomCache1     = 'roomOnlineRoomCache_1'; //存放房间id
    private $_roominfoCache            = 'roominfoCache';
    private $_privateHallID            = '@TGS#bCBD7UJFV'; //私聊观众大厅
    private $_cache_room_queue_key     = 'cache_room_queue';
    private $_cache_room_userid_key    = 'cache_room_userid_key';
    private $_cache_room_roomtop3      = 'cache_room_roomtop3_key_'; 
    private $_cache_room_roomtop4      = 'cache_room_roomtop4_key_'; 
    private $_cache_room_points        = 'cache_room_pints_hash_key_';
    private $_cache_room_heartbeattime_queue      = 'cache_room_heartbeattime_queue'; 
    private $_expireTime               = 60; //单位秒 60秒
    private $_default_frontcover       = 'http://a.197754.com/upload/default_frontcover.jpg';
    private $_cache_expire_time        = 21600; //6小时
    private $_playerHeartbeatTime      = 21; //app直播间主播21秒发送一次心跳

    /*******************************基础配置结束*******************************/

    public function __construct()
    {
        $arr                        = explode("\\", __CLASS__);
        $model                      = $arr[count($arr) - 1]; //获取当前模型
        $mp["id_alias"]             = $this->id_alias;
        $mp["slave_model"]          = $this->slave_model;
        $mp["slave_relation_field"] = $this->slave_relation_field;
        $mp["flag_model"]           = $this->flag_model;
        $mp["flag_opt"]             = $this->flag_opt;
        parent::__construct($model, $mp);
    }

    public $data;

    public $error;

    public static $state = [
        '0' => '离线',
        '1' => '在线',
        '4' => '封禁',
    ];

    public function index($params)
    {
        extract($params);
        model('Room')->alias('a')->join('__ACCOUNT__ b', 'b.userid= a.userid');
        if ($search['sval']) {
            if ($search['stype'] == 1) {
                model('Room')->where('a.userid', $search['sval']);
            }
            if ($search['stype'] == 2) {
                model('Room')->where('b.nickname', $search['sval']);
            }
            if ($search['stype'] == 3) {
                model('Room')->where('a.room_id', $search['sval']);
            }
        }
        $list = model('Room')->paginate($page_size);
//        safe_foreach($list);
        foreach ($list as $key => $value) {
            if ($list[$key]['state'] == 1) {
                $list[$key]['member_total'] = model('RoomDetail')->totalByRoomId($room_id);
            } else {
                $list[$key]['member_total'] = 0;
            }
        }
        // 获取分页显示
        $page = $list->render();
        // 模板变量赋值
        $this->data['list']   = $list;
        $this->data['page']   = $page;
        $this->data['search'] = $search;
        return true;
    }

    /**
     * 主播更新心跳
     * @param string $room_id
     * @param int $userid
     * @return bool
     */
    public function updLastTime($room_id, $userid)
    {
        //更改缓存的直播间时间
        $this->updRoomOnlineCache($userid, $room_id);
        $this->updRoomLasttimeCache($room_id);
        $this->removeCache();
        try
        {
            $colligateInfo = [
                'live_online_durtion' => $this->_playerHeartbeatTime,
            ];
            //写入直播间直播时长 [主播每日汇总数据]
            logic('AnchorDayColligate')->writeQueueData($userid, 1, $colligateInfo);
        }
        catch (Exception $e) {}
        //更新到数据库 如果再需要优化
        /* model('RoomData')->updBy(['end_time' => time()], ['room_id' => $room_id]);
        return model('Room')->updLastTime($room_id); */
        return $this->writeQueueHeartbeattime($room_id);
    }
    
    /**
     * 上传直播间信息
     * @param int $userid
     * @param string $title
     * @param string $frontcover
     * @param string $location
     * @param string $room_id
     * @param int $state
     * @param number $setOnline
     * @return boolean|unknown
     */
    public function uploadRoom($userid, $title, $frontcover, $location, $room_id, $state, $setOnline = 0)
    {
        $ret = false;
        while (true)
        {
            //获取用户信息
            $userinfo = logic('account')->getUserinfoByUserid($userid);
            
            //用户未通过认证
            if ($userinfo['identity_audit'] != 2)
            {
                throw new LogicException('', ERR_USERID_AUDIT);
                break;
            }
            else {}
            
            //用户未上传直播间封面
            if (!$frontcover)
            {
                $frontcover = $userinfo['frontcover'];
            }
            else {}
            
            //修改直播的信息
            $ret = model('Room')->uploadRoom($userid, $title, $frontcover, $location, $room_id, $state, $setOnline);
            //调用打开直播间
            $this->openRoom($room_id, $userid, true);
            //修改直播信息失败
            if ($ret == false)
            {
                break;
            }
            else {}
            
            //重新加载直播间信息
            $this->getRoominfoByRoomid($room_id, true);
            
            //主播创建直播重新将主播禁言的用户导入到新的群里
            try
            {
                //导入禁言信息
                logic('Gag')->createRoom($userid, $room_id);
            } catch (Exception $e) {}
            
            break;
        }
        return $ret;
    }

    //上传房间封面
    public function updCover($userid)
    {

        $file = request()->file();
        $keys = array_keys($file);
        $info = model('Room')->upload($keys[0]);

        if ($info) {
            $frontcover = $info[0]['url'];
            $bloon      = model('account')->updBy(['frontcover' => $frontcover], ['userid' => $userid]);
            if ($bloon) {
                return ['frontcover' => $frontcover];
            } else {
                throw new LogicException(genErrMsg(EC_DATABASE_ERROR), EC_DATABASE_ERROR);
            }
        } else {
            throw new LogicException(genErrMsg(ERR_UPLOAD), ERR_UPLOAD);
        }
    }

    /**
     *  获取正在直播的直播间
     * @return [type] [description]
     */
    public function roomList($page, $page_size)
    {
        $onlineRoom = [];
        $res        = model('room')->getOnlineRoom($page, $page_size);

        $roomDetailLogic = logic('RoomDetail');
        safe_foreach($res);
        foreach ($res as $val) {
            $bizId     = config('ZHIBO.APP_BIZID');
            $play_urls = getPlayUrl($bizId, $val['userid']);

            $val['member_total'] = $roomDetailLogic->roomOnlineMemberNumber($val['room_id']);

            $val['push_stream']      = $play_urls[0];
            $val['push_stream_flv']  = 'http://sleep-bshu.oss-cn-shenzhen.aliyuncs.com/shaonvshidai.flv';
            $val['push_stream_m3u8'] = $play_urls[2];

            unset($val['room_id']);
            //通过缓存获取直播间的在线人数
            $onlineRoom[] = $val;
        }
        return $onlineRoom;
    }
    /**
     * 生成新的房间id 房间id长度23char
     * @param int       $playerid       主播id
     * @param string    $api_version    当前接口版本
     * @return string
     */
    public function makeNewRoomid($playerid, $api_version = '')
    {
        $newRoomid = false;
        $pushStreamUrl = false;
        while (true)
        {
            //获取用户信息
            $userinfo = logic('account')->getUserinfoByUserid($playerid);
   
            //用户未通过认证
            if ($userinfo['identity_audit'] != 2)
            {
                throw new LogicException('', ERR_USERID_AUDIT);
            }
            else {}
            //获取直播间
            $roominfo = $this->infoByUseridRoomid($playerid, 0);
            //用户直播功能被锁定
            if ($userinfo['lock_state'] == 1 || $roominfo['lock_state'] == 1)
            {
                throw new LogicException('直播功能被封 请联系客服', ERR_ROOM_LOCK);
            }
            else {}
            $randnumber = rand(100, 999);
            $time       = time();
            $str        = $time . $randnumber;
            $randstr    = rand(16 * 16 * 16 + 1, 16 * 16 * 16 * 16 - 1);
            $randstr    = dechex($randstr);
            $newRoomid    = 'room_' . $str . '_' . $randstr;
            //将最新的roomid更新到数据库 xuyong 2018年11月6日15:06:46
            //修改直播的信息
            $ret = model('Room')->uploadRoom($playerid, $userinfo['nickname'], $userinfo['frontcover'], '', $newRoomid, 1);
            if ($ret == false)
            {
                throw new LogicException('直播间创建出错 请联系客服', EC_DATABASE_ERROR);
            }
            else {}
            //调用打开直播间 - by tw 2018-11-16
            $this->openRoom($newRoomid, $playerid, true);
            //设置主播与直播间的关系 最新的直播间id
            $cacheObj = redis_cache::instance();
            $key = $this->_cache_room_userid_key;
            $cacheObj->hSet($key, $playerid, $newRoomid);
            //判断版本号
            if (controllerApiVersion($api_version, 20181107, ''))
            {
                //组合推流地址
                $bizId          = config('ZHIBO.APP_BIZID');
                //流id 用户id
                $streamId       = $playerid;
                //推流防盗key
                $streamKey      = config('ZHIBO.PUSH_KEY');
                //过期时间 当前时间+24小时
                $expireTime     = time() + 86400;
                $expireTime     = date("Y-m-d H:i:s", $expireTime);
                //获取推流地址
                $pushStreamUrl = getPushUrl($bizId, $streamId, $streamKey, $expireTime);
                //创建直播间
                $createGroupRet = logic('TxTimRestGear')->group_create_group('AVChatRoom', $newRoomid, $playerid, $newRoomid);
                if ($createGroupRet['code'] != EC_OK)
                {
                    throw new LogicException('AVChatRoom群创建出错 请联系客服', ERR_UNKNOWN_ERROR);
                }
                else {}
            }
            else {}
            break;
        }
        $dataRet = [
            'room_id'        => $newRoomid,
            'push_stream'   => $pushStreamUrl,
        ];
        return $dataRet;
    }
    
    
    /**
     * 通过主播id获取直播间
     * @param string $playerid
     * @param string $reset
     * @return boolean|array
     */
    public function getRoominfoByPlayer($playerid, $reset = false)
    {
        $roomInfo = false;
        while (true)
        {
            //获取直播间id
            $roomid = $this->getRoomidByPlayer($playerid, $reset);
            if (empty($roomid) == true)
            {
                break;
            }
            else {}
            //获取直播
            $roomInfo = $this->getRoominfoByRoomid($roomid, $reset, 0);

            //正在直播设置直播地址
            if ($roomInfo['is_online'] == 1)
            {
                $playerInfo = $this->bindInfoMore($roomInfo);
                $roomInfo['play_url_rtmp'] = $playerInfo['play_url_rtmp'];
                $roomInfo['play_url_flv'] = $playerInfo['play_url_flv'];
                $roomInfo['play_url_m3u8'] = $playerInfo['play_url_m3u8'];
            }
            else {}
           
            break;
        }
        return $roomInfo;
    }
    
    /**
     * 通过主播id获取直播间id
     * @param string $playerid
     * @param string $reset
     * @return boolean|array
     */
    public function getRoomidByPlayer($playerid, $reset = false)
    {
        $roomid = false;
        while (true)
        {
            $cacheObj = redis_cache::instance();
            $key = $this->_cache_room_userid_key;
            //通过缓存获取
            if ($cacheObj->hExists($key, $playerid) && $reset == false)
            {
                $roomcacheid = $cacheObj->hMget($key, $playerid);
            }
            else {}
            
            //直播间id已经设置
            if (empty($roomcacheid) == true)
            {
                //通过数据库获取
                $roomModel = model('Room');
                $roomInfo = [];
                $roomModel->getRoomInfo($playerid, $roomInfo);
                //数据库不存在
                if (empty($roomInfo))
                {
                    break;
                }
                else {}
                $roomid = $roomInfo['room_id'];
                //写入到缓存中
                $cacheObj->hSet($key, $playerid, $roomid);
                break;
            }
            else 
            {
                $roomid = $roomcacheid[$playerid];
            }
            break;
        }
        return $roomid;
    }
    
    /**
     * 获取直播间信息
     * @param int $userid
     * @param int $room_id
     * @return number|number[]|unknown[]|\app\admin\logic\unknown[]
     */
    public function room_init($userid, $room_id)
    {
        //获取直播间信息
        $info  = $this->getRoominfoByRoomid($room_id, false, 0);
        
        $total_jifen = $info['total_jifen'];
        $day_jifen   = $info['day_jifen'];
        $attentid    = $info['userid'];
        
        $onlineNum = logic('RoomDetail')->roomOnlineMemberNumber($room_id);

        $info = [
            'total_jifen'   => $total_jifen,
            'day_jifen'     => $day_jifen,
            'attent'        => 1,
            'online_num'    => $onlineNum,
        ];
        
        //获取用户是否关注主播
        $exists = logic('Attention')->infoByAttention($userid, $attentid);
        if ($exists == false) 
        {
            $info['attent'] = 0;
        } 
        else {}
        return $info;
    }
    /**
     * 解散直播间
     * @date 2018年6月28日10:45:53
     * @param string $room_id
     * @param string $userid
     * @return boolean
     */
    public function offlineRoom($room_id, $userid)
    {
        $info = model('RoomData')->infoBy(['room_id' => $room_id]);
        if ($info['userid'] != $userid) {
            throw new LogicException(ERR_ROOM_VISIT);
        }
        if ($info) {
            $data = [
                'end_time' => time(),
                'state'    => 0,
            ];
            model('RoomData')->updBy($data, ['id' => $info['id']]);
            //修改主播状态
            model('Account')->updBy(['state' => 0], ['userid' => $userid]);
        }
        //将全部成员状态置为下线
        return logic('RoomDetail')->closeRoom($room_id);
    }
    /**
     * 开启直播间
     * @date 2018年6月28日10:46:05
     * @param string $room_id
     * @param string $userid
     * @return boolean
     */
    public function openRoom($room_id, $userid, $iswrite = false)
    {
        $ret = false;
        while (true)
        {
            if ($iswrite == false)
            {
                break;
            }
            else {}
            
            //将用户状态设置成正在直播
            model('Account')->updBy(['state' => 3], ['userid' => $userid]);
            //RoomData添加一条记录
            $data               = [];
            $data['room_id']    = $room_id;
            $data['userid']     = $userid;
            $data['start_time'] = time();
            $data['state']      = 1;

            /* try
            {
                $ret = model('RoomData')->add($data);
            } catch (ModelException $me) {echo $me->getMsg();} catch (BaseException $be) {echo $me->getMsg();} catch (\Exception $e) {
                echo $e->getMessage();
            } */
            $ret = model('RoomData')->add($data);
             //有addtime，暂时不用写入starttime wh 2018/11/21
            //开播时把开播时间写入房间缓存
            $roomArr = $this->batchRoominfo([$room_id]);
            if(!isset($roomArr[$room_id]))
            {
                $roomArr[$room_id] = [];
            }
            $roominfo = $roomArr[$room_id];
            $roominfo['openroomtime'] = time();
            $redis = redis_cache::instance();
            $keyCache = $this->_roominfoCache;
            //写入用户缓存
            $redis->hMset_serialize($keyCache, [$room_id => $roominfo]);
            
            break;
        }
        if (empty($ret)) {
            return false;
        }
        return true;
    }
    /**
     * 进入直播间
     * @param string $room_id
     * @param string $userid
     * @throws LogicException
     * @return boolean|array
     */
    public function inRoom($room_id, $userid)
    {
        $result = false;
        while (true) {
            //直播间被锁定
            $roominfo = $this->getRoominfoByRoomid($room_id, false, 0);
            
            //将用户设置到房间用户
            $result = logic('RoomDetail')->onLine($room_id, $userid);

            //用户加入直播间
            $txCode = logic('TxTimRestGear')->group_add_group_member($room_id, $userid, 1);
            logic('Statistics')->inRoomRecord($room_id, $userid);
            $result = [];
            //加入房间状态
            if ($txCode['code'] == EC_OK)
            {
                $result['op_group'] = 1;
            }
            else
            {
                $result['op_group'] = 0;
            }
            break;
        }
        return $result;
    }
    /**
     * 退出直播间
     * @param string $room_id
     * @param string $userid
     * @throws LogicException
     * @return boolean|array
     *
     */
    public function outRoom($room_id, $userid)
    {
        $result = false;
        while (true) {
            $result = logic('RoomDetail')->offLine($room_id, $userid);

            //用户退出直播间
            $txCode = logic('TxTimRestGear')->group_delete_group_member($room_id, $userid, 1);
            $result = [
                'op_group' => '0',
                'points'   => '0',
                'diamond'  => '0',
                'intimacy' => '0',
                'duration' => '0',
            ];
            //加入房间状态
            if ($txCode['code'] == EC_OK)
            {
                $result['op_group'] = '1';
            }
            else
            {
                $result['op_group'] = '0';
            }

            $userinfo = logic('Account')->getUserinfoByUserid($userid);
            if(isset($userinfo['identity_audit']) && $userinfo['identity_audit'] == 2)//主播退出，给出结算信息
            {
                $room_info = $this->getRoominfoByRoomid($room_id);
                foreach ($result as $key => $val)
                {
                    $this->makeAnchorOutRoomInfo($result,$room_info,$key);
                }
            }

            break;
        }
        return $result;
    }

    /**
     * @param $result
     * @param $room_info
     * @param $pointsKey
     */
    protected function makeAnchorOutRoomInfo(&$result,$room_info,$pointsKey)
    {
        while(true)
        {
            if(!$room_info)
            {
                break;
            }

            if($pointsKey == 'duration')
            {
                $time_long = time() - $room_info['openroomtime'];
                $result['duration'] = $this->formatTime($time_long);
                break;
            }

            if(!isset($room_info[$pointsKey]))
            {
               break;
            }
            $result[$pointsKey] = $room_info[$pointsKey].'';
            break;
        }
    }

    /**
     * 返回时分秒格式的时间 00:01:00
     * @param $time
     * @return string
     */
    public function formatTime($time)
    {
        $h = str_pad(floor($time/3600),2,'0',STR_PAD_LEFT);

        $time = $time - $h * 3600;
        $m = str_pad(floor($time/60),2,'0',STR_PAD_LEFT);

        $s = str_pad($time - $m * 60,2,'0',STR_PAD_LEFT);

        return "{$h}:{$m}:{$s}";
    }

    /**
     * 通过主播获取房间
     * @param string $userid
     * @throws LogicException
     * @return array
     */
    public function infoByUser($userid)
    {
        $result = false;
        while (true) {
            $roomInfo = $this->infoByUseridRoomid($userid, 0);

            if ($roomInfo == false)
            {
                throw new LogicException('', ERR_ROOM_NULL);
            }
            else {}
            //绑定直播间更多信息
            $result = $this->bindInfoMore($roomInfo);

            $this->updRoominfoCache($result);
            break;
        }
        return $result;
    }
    /**
     * 通过直播间id 或者 主播id 查找直播间
     * @param string    $selid      主播id 或者 直播间id
     * @param int       $idtype     0通过主播id查找 1通过直播间id查找
     * @return boolean|string
     */
    public function infoByUseridRoomid($selid, $idtype)
    {
        $roomInfo  = false;
        $roomModel = model('Room');
        if ($idtype === 0) //通过主播id查找
        {
            $roomInfo = [];
            $roomModel->getRoomInfo($selid, $roomInfo);
        } else //通过房间id查找
        {
            $roomInfo = $roomModel->infoByRoomid($selid, ['userid']);
        }
        //判断房间是否存在
        if (empty($roomInfo) == false)
        {
            $roomInfo['lasttime'] = isset($roomInfo['lasttime']) ? $roomInfo['lasttime'] : 0;
            //判断房间是否在线
            $roomInfo['is_online'] = time() - $roomInfo['lasttime'] <= $this->_expireTime ? 1 : 0;
        }
        else 
        {
            $roomInfo  = false;
        }
       
        return $roomInfo;
    }
    /**
     * 添加或者删除私有房间用户状态
     * @param string $userid
     * @param integer $state
     */
    public function videocall($userid, $state = 0)
    {
        $redis = redis_cache::instance();
        $key   = $this->_privateRoomOnlineCache;
        if ($state == 1) {
            $redis->zRem($key, $userid);
        } else {
            $redis->zAdd($key, time(), $userid);
        }
        $this->notificationCall();
        return true;
    }
    /**
     * 获取视频聊天在线列表
     * @return array[]
     */
    public function videocallOnline()
    {
        $redis = redis_cache::instance();
        $key   = $this->_privateRoomOnlineCache;
        //获取当前私有聊天列表
        $onlineUser          = $redis->zRangeByScore($key, time() - $this->_expireTime, time());
        $onlineUserinfoList  = logic('Account')->batchUserinfo($onlineUser);
        $onlinevideocallUser = [];
        if ($onlineUserinfoList != false) {
            safe_foreach($onlineUserinfoList);
            foreach ($onlineUserinfoList as $val) {
                if ($val != false) {
                    $userinfoTemp                 = [];
                    $bizId                        = config('ZHIBO.APP_BIZID');
                    $push_stream                  = getPlayUrl($bizId, $val['userid'])[0];
                    $userinfoTemp['userid']       = $val['userid'];
                    $userinfoTemp['nickname']     = $val['nickname'];
                    $userinfoTemp['avatar']       = $val['avatar'];
                    $userinfoTemp['chat_deplete'] = $val['chat_deplete'];
                    $userinfoTemp['chat_minute']  = $val['chat_minute'];
                    $userinfoTemp['push_stream']  = $push_stream;
                    //$onlinevideocallUser[]          = $userinfoTemp;
                    array_push($onlinevideocallUser, $userinfoTemp);

                }
            }
        }
        $retList = [
            'roomid' => $this->_privateHallID,
            'list'   => $onlinevideocallUser,
        ];
        return $retList;
    }

    /**
     * 通过房间id获取
     * @param unknown $roomid
     * @param string $reset
     * @throws LogicException
     * @return unknown
     */
    public function getRoominfoByRoomid($roomid, $reset = false, $lockstate = -1)
    {
        $roominfo = false;
        while (true) {
            $result = $this->batchRoominfo([$roomid], $reset);
            if ($result == false || empty($result[$roomid])) 
            {
                throw new LogicException($roomid . genErrMsg(ERR_ROOM_NULL), ERR_ROOM_NULL);
            }
            else {}
            $roominfo = $result[$roomid];

            //设置是否在线
            if (isset($roominfo['lasttime']) && time() - $roominfo['lasttime'] < $this->_expireTime)
            {
                $roominfo['is_online'] = 1;
            }
            else
            {
                $roominfo['is_online'] = 0;
            }
            
            //判断房间状态
            if ($lockstate != 0)
            {
                break;
            }
            else {}
            
            //房间被锁定
            if ($roominfo['lock_state'] != 0)
            {
                throw new LogicException($roomid . genErrMsg(ERR_ROOM_LOCK), ERR_ROOM_LOCK);
            }
            else {}
            
            //获取积分
            $pointsInfo = $this->getRoomPoints($roominfo['userid']);
            if (empty($pointsInfo) == true)
            {
                break;
            }
            else
            {
                $roominfo['today']          = $pointsInfo['today'];
                $roominfo['day_jifen']      = $pointsInfo['day_jifen'];
                $roominfo['total_jifen']    = $pointsInfo['total_jifen'];
            }
            break;
        }
        return $roominfo;
    }
    /**
     * 批量获取房间
     * @param array $roomid
     * @param boolean $reset true 重新加载缓存
     * @return array|boolean
     */
    public function batchRoominfo(array $roomid, $reset = false)
    {
        $redis      = redis_cache::instance();
        $keyCache   = $this->_roominfoCache;
        $selRoomids = [];
        if ($reset == false) 
        {
            $roominfoList = $redis->hMget_serialize($keyCache, $roomid);
            safe_foreach($roominfoList);
            foreach ($roominfoList as $k => $val) 
            {
                if (empty($val) == true || isset($val['room_id']) == false) 
                {
                    array_push($selRoomids, $k);
                }
            }
        } 
        else 
        {
            //关闭缓存
            $roominfoList = false;
        }
        if ($roominfoList == false) 
        {
            $selRoomids = $roomid;
        }
        if (!empty($selRoomids)) 
        {
            $selRoominfoList = model('Room')->listByRoomid($selRoomids);
            $writeRoominfosCache = [];

            safe_foreach($selRoominfoList);
            foreach ($selRoominfoList as $val) 
            {
                $roominfoList[$val['room_id']]        = $val;
                $writeRoominfosCache[$val['room_id']] = $val;
            }
            $redis->hMset_serialize($keyCache, $writeRoominfosCache);
            $redis->expire($keyCache, 7200); //保存2小时
        }
        $roominfoList = empty($roominfoList) ? false : $roominfoList;
        $roominfoRetList = [];
        safe_foreach($roominfoList);
        //过滤掉没有roomid的直播 xuyong2018年11月6日15:16:33
        foreach ($roominfoList as $valRoominfo)
        {
            if (isset($valRoominfo['room_id']) == true)
            {
                $roominfoRetList[$valRoominfo['room_id']] = $valRoominfo;
            }
            else {}
        }
        return $roominfoRetList;
    }
    /**
     * 更新缓存的心跳最后时间
     * @param string $roomid
     */
    public function updRoomLasttimeCache($roomid)
    {
        //获取直播间
        $roomArr = $this->batchRoominfo([$roomid]);
        while (true) {
            if ($roomArr == false) {
                break;
            }
            $roominfo = $roomArr[$roomid];
            $roominfo['lasttime'] = time();

            $redis = redis_cache::instance();
            //设置用户播放时长
            $keyCache = $this->_roominfoCache;
            
            //写入用户缓存
            $redis->hMset_serialize($keyCache, [$roomid => $roominfo]);
            break;
        }
    }
    
    /**
     * 更新直播间到缓存
     * @param array $roominfo
     */
    public function updRoominfoCache($roominfo)
    {
        while (true) {
            //直播间roomid未设置
            if (empty($roominfo) || empty($roominfo['room_id']))
            {
                break;
            }
            else {}
            $redis = redis_cache::instance();
            //设置用户播放时长
            $keyCache = $this->_roominfoCache;
            $roomid = $roominfo['room_id'];
            //写入用户缓存
            $redis->hMset_serialize($keyCache, [$roomid => $roominfo]);
            break;
        }
    }
    /**
     * 更新房间在线列表
     * @param int $roomId
     */
    public function updRoomOnlineCache($userid, $roomid)
    {
        redis_cache::instance()->zAdd($this->_roomOnlineRoomCache, time(), $userid);
        redis_cache::instance()->zAdd($this->_roomOnlineRoomCache1, time(), $roomid);
    }
    /**
     * 获取在线房间列表 用户id
     */
    public function listByRoomOnline($page = 0, $pagesize = 500)
    {
        //获取最近20秒的在线房间
        $playeridList = redis_cache::instance()->zRevRangeByScore(
            $this->_roomOnlineRoomCache, time() - $this->_expireTime, time(), ['limit' => array($page, $pagesize)]);
        //$roominfoList = $this->bindOnlineRoom($roomidList);
        //排序
        sort($playeridList);
        return $playeridList;
    }
    /**
     * 获取在线房间列表
     */
    public function listByRoomOnline_Roomid($page = 0, $pagesize = 500)
    {
        //获取最近20秒的在线房间
        $roomidList = redis_cache::instance()->zRevRangeByScore(
            $this->_roomOnlineRoomCache1, time() - $this->_expireTime, time(), ['limit' => array($page, $pagesize)]);
        //排序
        sort($roomidList);
        //$roominfoList = $this->bindOnlineRoom($roomidList);
        if (empty($roomidList)) {
            return false;
        }
        return $roomidList;
    }

    /**
     * 获取热门直播间
     * @param string $userid
     * @param int $pageSize
     * @return array
     */
    public function room_top($lastUserid, $pageSize)
    {
        //获取送礼的排行榜
        $useridList = logic('GivingGift')->readNearRoomTopOnline();
        //获取在线的直播间
        $useridOnlineList = $this->listByRoomOnline(0, 1000);

        //推荐的直播间
        $topUseridList = [];
        //获取推荐的直播间 只有第一页才请求推荐的直播间
        //if (empty($lastUserid))
        {
            //获取推荐的主播id
            $topUseridList = logic('RoomRecommend')->listUseridByState();
        }

        //合并直播间
        $useridAllList = array_merge($topUseridList, $useridList, $useridOnlineList);
        //去重复
        $useridAllList = array_unique($useridAllList);

        //lastUserid为空不要分页
        if (empty($lastUserid) == false)
        {
            $useridList = Lists::page_value_array($useridAllList, $pageSize, $lastUserid);
        }
        else 
        {
            $useridList = $useridAllList;
        }

        //获取用户详情
        $onlinePlayerList = logic('Account')->listUserByUseridList($useridList, 1, 1);
        $result           = $this->bindOnlineRoom($onlinePlayerList);

        return ['list' => $result];
    }
    /**
     * 获取热门直播间 中间加入广告与一对一视频聊天
     * @param string $lastUserid
     * @param int $pageSize
     * @return array
     */
    public function room_top3($lastUserid, $pageSize, $agentId = 0, $reset = false)
    {
        $retList = [];

        while (true)
        {
            $cacheObj = redis_cache::instance();
            $key = $this->_cache_room_roomtop3 . $pageSize . $lastUserid;
            
            //缓存是否存在
            if ($cacheObj->exists($key) && $reset == false)
            {
                $retList = $cacheObj->get_object($key);
            }
            else {}
            
            //retlist 值存在跳出
            if (empty($retList) == false)
            {
                break;
            }
            else {}
            
            //获取直播间列表
            $result = $this->room_top($lastUserid, $pageSize);
            
            //写入主播的用户id
            $useridList = array();
            $onlinePlayerList = array();

            safe_foreach($result['list']);
            //家族id
            foreach ($result['list'] as $valPlayer)
            {
                $useridList[] = $valPlayer['userid'];
                $valPlayer['itemCategory'] = 'type_room';
                $onlinePlayerList[] = $valPlayer;
            }
            
            //预设的主播都是在第一页显示 当传入的lastuserid 不为空就不绑定预设主播数据 与广告位
            if (empty($lastUserid))
            {
                $toExamine = logic('ToExamineAgent')->isAgentid($agentId);
                //绑定预设置的主播
                $onlinePlayerList = logic('OnlineSet')->bindSetRoom3($onlinePlayerList, $useridList, $toExamine);
   
                //绑定家族
                $retList = logic('FamilyMember')->bindRoomFamily($useridList, $onlinePlayerList);
                
                logic('Adv')->bindPlatformAdv($retList,3,false);
            }
            else 
            {
                //绑定家族
                $retList = logic('FamilyMember')->bindRoomFamily($useridList, $onlinePlayerList);
            }
     
            $cacheObj->set_object($key, $retList);
            //数据保存20秒
            $cacheObj->expire($key, 20);
            break;
        }
        return $retList;
    }
    /**
     * 获取热门直播间 中间加入广告与一对一视频聊天
     * @param string $lastUserid
     * @param int $pageSize
     * @param bool $reset
     * @param bool $needAdv 是否需要广告
     * @return array
     */
    public function room_top4($lastUserid, $pageSize, $reset = false, $needAdv = false, $agentId = 0)
    {
        $retList = [];
        while (true)
        {
            $cacheObj = redis_cache::instance();
            //获取是否是过审渠道
            $toExamine = logic('ToExamineAgent')->isAgentid($agentId);
            global $_G_USERID;
            $userinfo = logic('Account')->getUserinfoByUserid($_G_USERID);
            if (empty($userinfo['vip']) == false)
            {
                $key = $this->_cache_room_roomtop4 . $pageSize . $lastUserid . '_' . $toExamine . '_1';
            }
            else
            {
                $key = $this->_cache_room_roomtop4 . $pageSize . $lastUserid . '_' . $toExamine . '_0';
            }
            //缓存是否存在
            if ($reset == false && $cacheObj->exists($key))
            {
                $retList = $cacheObj->get_object($key);
            }
            else {}
            //retlist 值存在跳出
            if (empty($retList) == false)
            {
                break;
            }
            else {}
            
            //写入主播的用户id
            $useridList = array();
            $onlinePlayerList = array();
            
            //过审渠道 不读取在线直播间
            if ($toExamine == 0)
            {
                //获取直播间列表
                $result = $this->showByPlayer($lastUserid, $pageSize);
                safe_foreach($result);
                //家族id
                foreach ($result as $valPlayer)
                {
                    $useridList[] = $valPlayer['userid'];
                    $onlinePlayerList[] = $valPlayer;
                }
            }
            else {}
            
            //预设的主播都是在第一页显示 当传入的lastuserid 不为空就不绑定预设主播数据 与广告位
            if (empty($lastUserid))
            {
                //绑定预设置的主播
                $onlinePlayerList = logic('OnlineSet')->bindSetRoom3($onlinePlayerList, $useridList, $toExamine);
                //获取预设的1对1通话
                $videoChatList = logic('OnlineSet')->showVideoChat($toExamine);
                //合并预设主播
                $setRoomList = array_merge($onlinePlayerList, $videoChatList);
                //绑定家族
                $retList = logic('FamilyMember')->bindRoomFamily($useridList, $setRoomList);
                //获取广告位
                logic('Adv')->bindPlatformAdv($retList,3,$needAdv);
            }
            else
            {
                //绑定家族
                $retList = logic('FamilyMember')->bindRoomFamily($useridList, $onlinePlayerList);
            }
            $cacheObj->set_object($key, $retList);
            //数据保存20秒
            $cacheObj->expire($key, 20);
            break;
        }
        
        return $retList;
    }
    /**
     * 获取全部主播
     * @param string   $lastUserid 上页结束的用户id
     * @param int      $pageSize    当前页大小
     * @param boolean  $reset 是否重新读取缓存
     * @return array
     */
    public function showByPlayer($lastUserid, $pageSize, $reset = false)
    {
        $playerList = [];
        //获取推荐主播
        $topUseridList = [];
        //获取推荐的直播间 只有第一页才请求推荐的直播间 
        //if (empty($lastUserid))
        {
            //获取推荐的主播id
            $topUseridList = logic('RoomRecommend')->listUseridByState();
        }
        //获取在线主播
        $playeridOnlineList = logic('Account')->getPlayerOnline();
        if (empty($playeridOnlineList) == false) //设置在线主播
        {
            //绑定
            $playerList = $this->bindHomePage($playeridOnlineList, $topUseridList);
            //分页
            $playerList = Lists::page_value_array($playerList, $pageSize, $lastUserid, 'userid');
        }
        else {}
        return $playerList;
    }
    /**
     * 绑定首页需要显示的数据
     * @param array $playeridList       需要绑定的主播id
     * @param array $topUseridList      被推荐的主播id
     * @param array $playeridOnlineList 在线主播 可以为空
     * @param string $honepage          是否是首页显示 为true 不绑定勿扰用户与不在线用户
     * @return unknown
     */
    public function bindHomePage(array $playeridList, array $topUseridList, array $playeridOnlineList = [], $honepage = true)
    {
        $playerList = [];

        //获取正在直播的用户
        $roomOnlineList = $this->listByRoomOnline();
        //获取正在视频聊天的用户
        $videoCallList = logic('VideoChat')->getAllInVideoChatUserList();
        //将直播，视频主播合并到在线用户列表中防止腾讯接口返回不在线
        $playeridList = array_merge($playeridList, $roomOnlineList, $videoCallList);
        if (empty($playeridList) == false) //设置在线主播
        {
            $playeridList = array_unique($playeridList);
            //获取用户详细
            $playerinfoList = logic('Account')->listUserByUseridList($playeridList, -1, -1);
            /* //获取正在直播的用户
            $roomOnlineList = $this->listByRoomOnline();
            //获取正在视频聊天的用户
            $videoCallList = logic('VideoChat')->getInVideoChatUserList($playeridList); */
            
            safe_foreach($playerinfoList);
            //循环设置主播当前状态
            foreach ($playerinfoList as $valUserinfo)
            {
                if (empty($valUserinfo) == true) //未获取到
                {
                    continue;
                }
                else {}
                
                $useridTemp = $valUserinfo['userid'];
                //获取用户上传的图片
                $myImageListTemp = logic('UploadFile')->showFileListUser($useridTemp, 0, 2);
                
                $defaultFrontcoverTemp = $this->_default_frontcover;
                
                //如果海报和头像一样 将使用默认头像
                if (empty($valUserinfo['frontcover']) == true || $valUserinfo['frontcover'] == $valUserinfo['avatar'])
                {
                    $valUserinfo['frontcover'] = $this->_default_frontcover;
                }
                else
                {
                    $defaultFrontcoverTemp = $valUserinfo['frontcover'];
                }
                $myImageList = [];
                //将用户上传的图片设置到用户封面字段
                if (count($myImageListTemp) > 0)
                {
                    $myImageDefault = $myImageListTemp[0]['file_path'];
                    //将第一张图片设置成用户海报
                    if (empty($myImageDefault) == false)
                    {
                        $valUserinfo['frontcover'] = $myImageDefault;
                    }
                    else {}
                    safe_foreach($myImageListTemp);
                    foreach ($myImageListTemp as $valImage)
                    {
                        $myImageList[] = [
                            'file_path' => $valImage['file_path'],
                            'img_path'  => $valImage['img_path'],
                        ];
                    }
                }
                else 
                {
                    $myImageList[] = [
                        'file_path' => $defaultFrontcoverTemp,
                        'img_path'  => $defaultFrontcoverTemp,
                    ];
                }
                if (in_array($useridTemp, $roomOnlineList) == true) //设置正在直播的用户
                {
                    //正在直播的直播间绑定
                    $roominfoTemp = $this->bindOnlineRoom([$valUserinfo]);
                    if (empty($roominfoTemp) == false && count($roominfoTemp) == 1)
                    {
                        $valUserinfo = $roominfoTemp[0];
                    }
                    else {}
                    $valUserinfo['itemCategory'] = 'type_room'; //正在直播
                    $valUserinfo['sort'] = 0;
                    $selValTemp = [$valUserinfo];
                }
                elseif (array_key_exists($useridTemp, $videoCallList) == true) //设置正在视频聊天的用户
                {
                    $valUserinfo['itemCategory'] = 'type_videocall'; //正在视频对话
                    $valUserinfo['sort'] = 4000;
                }
                elseif ($honepage == true || in_array($useridTemp, $playeridOnlineList) == true)    //设置在线
                {
                    if ($valUserinfo['quite'] == 1) //开启勿扰模式
                    {
                        if ($honepage == true)
                        {
                            continue;
                        }
                        else 
                        {
                            $valUserinfo['itemCategory'] = 'type_quite'; //勿扰
                            $valUserinfo['sort'] = 6000;
                        }
                    }
                    else 
                    {
                        $valUserinfo['itemCategory'] = 'type_free'; //空闲
                        $valUserinfo['sort'] = 0;
                    }
                }
                else //用户不在线
                {
                    if ($honepage == true)
                    {
                        continue;
                    }
                    else
                    {
                        $valUserinfo['itemCategory'] = 'type_offline'; //离线
                        $valUserinfo['sort'] = 8000;
                    }
                }
                if (in_array($useridTemp, $topUseridList) == true) //如果是推荐的主播在当前的排序上+1
                {
                    $topKey = array_search($useridTemp,$topUseridList);
                    $valUserinfo['sort'] = $valUserinfo['sort'] + $topKey;
                }
                else {}
                //设置my_image_list
                $valUserinfo['my_image_list'] = $myImageList;
                
                $playerList[] = $valUserinfo;
            }
        }
        else {}
        $playerList = Lists::sort($playerList, 'sort asc');
        return $playerList;
    }
    /**
     * 绑定正在直播的房间信息
     * @param array $userList
     * @return array
     */
    public function bindOnlineRoom($userList)
    {
        $roomidList = [];
        $playerList = [];

        safe_foreach($userList);
        //组合直播间id
        foreach ($userList as $val) {
            //用户没有直播房间
            if ($val['room_id'] !== 0)
            {
                $roomidList[] = $val['room_id'];
                $playerList[$val['userid']] = $val;
            }
            else {}
        }
        //获取房间详情
        $roominfoList = $this->batchRoominfo($roomidList);
        //绑定返回信息
        $roomonlineList = [];
        safe_foreach($roominfoList);
        foreach ($roominfoList as $key => $val) {
            $playerInfo   = $playerList[$val['userid']];
            $member_total = logic('RoomDetail')->roomOnlineMemberNumber($val['room_id']);
            //设置流地址
            $bizId     = config('ZHIBO.APP_BIZID');
            $play_urls = getPlayUrl($bizId, $playerInfo['userid']);
            $is_online = 0;
            if (isset($val['lasttime']) && time() - $val['lasttime'] < $this->_expireTime)
            {
                $is_online = 1;
            }
            else {}
            $frontcover = empty($val['frontcover']) == true ? $playerInfo['frontcover'] : $val['frontcover'];
            array_push($roomonlineList, [
                'userid'           => $playerInfo['userid'],
                'title'            => $val['title'],
                'frontcover'       => $frontcover,
                'nickname'         => $playerInfo['nickname'],
                'avatar'           => $playerInfo['avatar'],
                'roomid'           => $val['room_id'],
                'user_frontcover'  => $playerInfo['frontcover'],
                'member_total'     => $member_total,
                'is_online'        => $is_online,
                'push_stream'      => $play_urls[0],
                'push_stream_flv'  => $play_urls[1],
                'push_stream_m3u8' => $play_urls[2],
            ]);
        }
        return $roomonlineList;
    }
    /**
     * 绑定直播房间信息
     * @param array $userList
     * @return array
     */
    public function bindRoom($userList)
    {
        $roomidList = [];
        $playerList = [];
        safe_foreach($userList);
        //组合直播间id
        foreach ($userList as $val) {
            //用户没有直播房间
            if ($val['room_id'] !== 0)
            {
                $roomidList[] = $val['room_id'];
            }
            else {}
            $playerList[$val['userid']] = $val;
        }
        //获取房间详情
        $roominfoList = $this->batchRoominfo($roomidList);

        //绑定返回信息
        $roomList = [];

        safe_foreach($playerList);
        foreach ($playerList as $valPlayer)
        {
            $nickname = $valPlayer['nickname'];
            $title = $valPlayer['nickname'];
            $userid = $valPlayer['userid'];
            $frontcover = $valPlayer['frontcover'];
            $avatar = $valPlayer['avatar'];
            $roomid = $valPlayer['room_id'];
            $user_frontcover = $valPlayer['frontcover'];
            $is_online = 0;
            $member_total = 0;
            $play_urls = ['', '', ''];
           
            //用户没有直播房间
            if ($valPlayer['room_id'] !== 0)
            {
                $roominfoTemp = $roominfoList[$valPlayer['room_id']];
                $title = $roominfoTemp['title'];
                $frontcover = $roominfoTemp['frontcover'];

                //设置流地址
                $bizId     = config('ZHIBO.APP_BIZID');
                $play_urls = getPlayUrl($bizId, $valPlayer['userid']);
                
                if (isset($roominfoTemp['lasttime']) && time() - $roominfoTemp['lasttime'] < $this->_expireTime)
                {
                    $is_online = 1;
                }
                else {}
                if ($is_online == 1)
                {
                    $member_total = logic('RoomDetail')->roomOnlineMemberNumber($val['room_id']);
                }
                else {}
            }
            else {}
 
            {
                $frontcover = $user_frontcover;
            }
            
            $roomList[] = [
                'userid'           => $userid,
                'title'            => $title,
                'frontcover'       => $frontcover,
                'nickname'         => $nickname,
                'avatar'           => $avatar,
                'roomid'           => $roomid,
                'user_frontcover'  => $user_frontcover,
                'member_total'     => $member_total,
                'is_online'        => $is_online,
                'push_stream'      => $play_urls[0],
                'push_stream_flv'  => $play_urls[1],
                'push_stream_m3u8' => $play_urls[2],
            ];
        }
        return $roomList;
    }

    /**
     * 视频通话队列改变发送广播消息
     * @param string $userid
     * @param string $roomid
     */
    private function notificationCall()
    {
        $redis = redis_cache::instance();
        $key   = $this->_privateRoomOnlineCache;
        $keyUp = $this->_privateRoomOnlineUpCache;

        //获取当前私有聊天列表
        $currentCallList = $redis->zRangeByScore($key, time() - $this->_expireTime, time());

        //获取上次排行榜私有聊天
        $upCallList   = $redis->get_object($keyUp);
        $diffUserList = false;
        while (true) {
            //当前私有聊天列表为空
            if (!is_array($currentCallList)) {
                break;
            }

            //上次私有聊天列表为空
            if (!is_array($upCallList)) {
                $is_update = true;
                break;
            }
            //判断列表是否一样
            $diffUserList = Lists::diff_value_array($currentCallList, $upCallList);
            break;
        }
        //私有聊天修改
        if ($diffUserList != false) {

            $noticeLogic = logic('TxTimRestGear');

            //写入需要改变
            $noticeLogic->msgTemplate['cmd']         = $noticeLogic::$msgType['PUBLIC_ROOM_GROIUP_SYS'];
            $noticeLogic->msgTemplate['data']['cmd'] = $noticeLogic::$cmdType['MSG_CUSTOM_PRIVATE_ROOM'];
            //写入缓存
            $redis->set_object($keyUp, $currentCallList);
            $onlineUser   = $diffUserList['diff_arr1'];
            $downlineUser = $diffUserList['diff_arr2'];
            //获取上线用户
            if (!empty($onlineUser)) {
                $onlineUserinfoList = logic('Account')->batchUserinfo($onlineUser);
                if ($onlineUserinfoList != false) {
                    safe_foreach($onlineUserinfoList);
                    //广播用户排行
                    foreach ($onlineUserinfoList as $val) {
                        if ($val != false) {
                            $userInfoTemp = [];
                            //设置流地址
                            $bizId                        = config('ZHIBO.APP_BIZID');
                            $push_stream                  = getPlayUrl($bizId, $val['userid'])[0];
                            $userInfoTemp['online']       = 1;
                            $userInfoTemp['userid']       = $val['userid'];
                            $userInfoTemp['nickname']     = $val['nickname'];
                            $userInfoTemp['avatar']       = $val['avatar'];
                            $userInfoTemp['chat_deplete'] = $val['chat_deplete'];
                            $userInfoTemp['chat_minute']  = $val['chat_minute'];
                            $userInfoTemp['push_stream']  = $push_stream;
                            array_push($noticeLogic->msgTemplate['data']['private_room'], $userInfoTemp);
                        }
                    }
                }
            }
            if (!empty($downlineUser)) {
                safe_foreach($downlineUser);
                //广播用户排行
                foreach ($downlineUser as $val) {
                    if ($val != false) {
                        $userInfoTemp           = [];
                        $userInfoTemp['online'] = 0;
                        $userInfoTemp['userid'] = $val;
                        array_push($noticeLogic->msgTemplate['data']['private_room'], $userInfoTemp);
                    }
                }
            }
            return $noticeLogic->send_group_system_notification($this->_privateHallID);
        }
    }

    /**
     * 绑定直播间更多信息
     * @param array $info
     * @throws LogicException
     * @return array
     */
    private function bindInfoMore($info)
    {
        $roomInfo = [];
        $roomInfo['room_id'] = $info['room_id'];
        $userid = $info['userid'];
        if ($info['set_online'] == 0)   //主播开启的主播间
        {
            //设置流地址
            $bizId     = config('ZHIBO.APP_BIZID');
            $play_urls = getPlayUrl($bizId, $userid);
            /* $streamInfo             = model('room_cb')->where('userid', $userid)->find();
             if (empty($streamInfo))
             {
             throw new LogicException('', ERR_STREAM_NULL, $userid . ' 用户 流获取失败');
             } */
            //$roomInfo['play_url_rtmp']  = $play_urls[0] . '?' . $streamInfo['stream_param'];
            $roomInfo['play_url_rtmp'] = $play_urls[0];
            $roomInfo['play_url_flv']  = $play_urls[1];
            $roomInfo['play_url_m3u8'] = $play_urls[2];
        }
        elseif ($info['set_online'] == 1)   //预设的主播间
        {
            //获取设置的直播间
            $onlinesetInfo = logic('OnlineSet')->infoByUserid($info['userid']);

            if (empty($onlinesetInfo) === false)
            {
                $roomInfo['play_url_rtmp'] = $onlinesetInfo['push_stream_flv'];
                $roomInfo['play_url_flv']  = $onlinesetInfo['push_stream_flv'];
                $roomInfo['play_url_m3u8'] = $onlinesetInfo['push_stream_m3u8'];
                $roomInfo['room_id'] = $onlinesetInfo['room_id'];
            }
            else {}
        }
        

        //设置主播信息
        $userInfo = model('account')->infoByUser($userid, 'nickname,avatar,frontcover');
        if (empty($userInfo)) {
            throw new LogicException('', ERR_ACCESS_LOCK, '$userid 流获取失败');
        }
        $roomInfo['userid']     = $userid;
        $roomInfo['nickname']   = $userInfo['nickname'];
        $roomInfo['avatar']     = $userInfo['avatar'];
        $roomInfo['frontcover'] = $userInfo['frontcover'];

        //设置直播间在线人数
        $roomInfo['member_total'] = model('RoomDetail')->totalByRoomId($info['room_id']);
        //设置直播间信息
        $roomInfo['title']   = $info['title'];
        //设置私聊信息
        $roomInfo['pushers'] = [[
            'accelerateURL' => $roomInfo['play_url_rtmp'],
            'userAvatar'    => $roomInfo['avatar'],
            'userID'        => $userid,
            'userName'      => $roomInfo['nickname'],
        ]];

        //只用作演示直播间展示
        if (in_array($roomInfo['userid'], ['58494913', '18776192', '19509909', '10588018', '44104321'])) {
            //$roomInfo['play_url_flv']  = 'rtmp://live.hkstv.hk.lxdns.com/live/hks';
            //$roomInfo['play_url_rtmp'] = 'rtmp://100100.rtmp.syun.tv:1935/live/14653482';
            $roomInfo['play_url_flv']  = 'http://sleep-bshu.oss-cn-shenzhen.aliyuncs.com/shaonvshidai.flv';
            $roomInfo['play_url_rtmp'] = 'http://sleep-bshu.oss-cn-shenzhen.aliyuncs.com/shaonvshidai.flv';
            $roomInfo['pushers']       = [[
                'accelerateURL' => 'http://sleep-bshu.oss-cn-shenzhen.aliyuncs.com/shaonvshidai.flv',
                'userAvatar'    => $info['avatar'],
                'userID'        => $userid,
                'userName'      => $info['nickname'],
            ]];
        }
        return $roomInfo;
    }

    /**
     * 清理送礼队列1-24小时的数
     * @return unknown
     */
    private function removeCache()
    {
        $isdel = mt_rand(1, 100);
        //随机一个数字删除 避免太频繁调用
        if ($isdel == 6) {
            //清理送礼队列1-24小时的数据
            $this->_cacheObj->zRemRangeByRank($this->_roomOnlineRoomCache, time() - 864000, time() - 3600);
            $this->_cacheObj->zRemRangeByRank($this->_roomOnlineRoomCache1, time() - 864000, time() - 3600);
        }
    }
    //**********************************优化队列****************************************
    /**
     * 写入主播的积分
     */
    public function execRoomQueueData()
    {
        $key = $this->_cache_room_queue_key;
        $cacheObj = redis_cache::instance();
        $queueList = $cacheObj->get_object($key);
        $cacheObj->del($key);
        
        //开启事务提交 性能为更快
        if(is_array($queueList) && !empty($queueList)){
            Db::startTrans();
            $rowNum = 0;
            safe_foreach($queueList);
            foreach ($queueList as $valRoom)
            {
                $rowNum++;
                $day_points  = $valRoom['day_jifen'];
                $totalPoints = $valRoom['total_jifen'];
                $today_queue = $valRoom['today'];
                $userid      = $valRoom['userid'];

                $upd_data = [
                    'day_jifen'     => $day_points,
                    'total_jifen'   => $totalPoints,
                    'today'         => $today_queue,
                ];

                $where = [
                    'userid' => $userid,
                ];

                $this->updBy($upd_data, $where);
            }
            echo("主播积分写入[$rowNum]条</br>");
            //提交事务
            Db::Commit();
        }

    }
    /**
     * 写入队列
     * @param array $pointsParam
     */
    private function writeQueue(array $roomParam, $playerid)
    {
        try
        {
            $key = $this->_cache_room_queue_key;
            $cacheObj = redis_cache::instance();
            $queueList = [];
            //房间用户赠送队列存在
            if ($cacheObj->exists($key))
            {
                $queueList = $cacheObj->get_object($key);
            }
            else {}

            $queueList[$playerid] = [
                'day_jifen'     => $roomParam['day_jifen'],
                'total_jifen'   => $roomParam['total_jifen'],
                'userid'        => $playerid,
                'today'         => $roomParam['today'],
            ];
            $cacheObj->set_object($key, $queueList);
        }
        catch (Exception $e) {}
    }

    /**
     * 写入心跳时间
     * @param string $roomid
     */
    private function writeQueueHeartbeattime($roomid)
    {
        $cacheObj = redis_cache::instance();
        $key = $this->_cache_room_heartbeattime_queue;
        while (true)
        {
            $heartbeatList = [];
            //是否存在
            if ($cacheObj->exists($key))
            {
                //从缓存中获取
                $heartbeatList = $cacheObj->get_object($key);
            }
            else {}
            
            //设置心跳队列的最后心跳时间
            $heartbeatList[$roomid] = time();
            
            //写入缓存
            $cacheObj->set_object($key, $heartbeatList);
            break;
        }
        return true;
    }
    /**
     * 将直播间心跳写入到数据中
     */
    public function execHeartbeatQueueData()
    {
        $cacheObj = redis_cache::instance();
        $key = $this->_cache_room_heartbeattime_queue;
        $queueList = $cacheObj->get_object($key);
        if(is_array($queueList) && !empty($queueList))
        {
            //开启事务提交 性能为更快
            Db::startTrans();
            $rowNum = 0;
            safe_foreach($queueList);
            foreach ($queueList as $keyRoom => $valRoom)
            {
                $rowNum++;
                $lasttime = $valRoom;
                $roomid = $keyRoom;
                logic('RoomData')->updBy(['end_time' => $lasttime], ['room_id' => $roomid]);
                model('Room')->updLastTime($keyRoom, $lasttime);
            }
            echo("直播间心跳更新[$rowNum]条</br>");
            //提交事务
            Db::Commit();
            $cacheObj->del($key);
        }else{}
    }
    /**
     * 根据主播id封禁/解封房间
     * @param $operator
     * @param $userid
     * @param $state
     */
    public function opBanByUserid($userid, $state)
    {
        //根据主播id获取房间记录信息
        $roomInfo = $this->infoByUseridRoomid($userid,0);
        if ($roomInfo == false) {
            throw new LogicException('房间不存在', ERR_ROOM_NULL);
        }
        $roomId = $roomInfo['room_id'];  // 房间id
        // 修改room表中lock_state
        $bloon = model('Room')->updLockState($roomId,$state);
        if (!$bloon) {
            throw new LogicException('', ERR_UNKNOWN_ERROR);
        }
        else
        {
            $room = $this->getRoominfoByRoomid($roomId, true);
        }
        if ($state == 1) // 封禁直播间
        {
            //解散直播间
            $res = logic('TxTimRestGear')->group_destroy_group($roomId);
            if (!$res) {
                throw new LogicException('解散房间错误', ERR_ROOM_DESTROY_NULL);
            }
            //关闭推流
            $roomCbInfo =logic('RoomCb')->infoByUserid($userid);
            if ($roomCbInfo)
            {
                $channel_id = $roomCbInfo['channel_id'];
                $ret = logic('LiveRestApi')->opSteam($channel_id,0);
                if ($ret['ret'] != 0)
                {
                    throw new LogicException($ret['massage'], $ret['ret']);
                }
            }
            else
            {
//                throw new LogicException('关闭推流错误', ERR_STREAM_NULL);
            }
            //推送封禁消息
            logic('Igt')->send_ban_message($userid, 'notice_cmd_room_close', '直播间已被封禁');
        }
        return true;
    }

    /**
     * 获取在线直播间数量
     * @return mixed
     */
    public function countOnlineRoom()
    {
        return model('Room')->countOnlineRoom();
    }
    
    /**
     * 修改房间积分
     * @param string $roomid
     * @param int $points
     */
    public function updRoomPoints($roomid, $points, $playerid = 0)
    {
        $pointsInfo = [];
        while (true)
        {
            //读取积分
            /*$pointsInfo = $this->getRoomPoints($playerid);
            if (empty($pointsInfo) == true)
            {
                break;
            }
            else {}
            $today = date('md');
            
            //当天日期一样
            if ($pointsInfo['today'] == $today)
            {
                $pointsInfo['day_jifen'] += $points;
            }
            else
            {
                $pointsInfo['day_jifen'] = $points;
                $pointsInfo['today'] = intval($today);
            }
            $pointsInfo['total_jifen'] += $points;
            //写入缓存
            $cacheObj = redis_cache::instance();
            $key = $this->_cache_room_points . $playerid;
            $cacheObj->set_object($key, $pointsInfo);
            $cacheObj->expire($key, $this->_cache_expire_time);*/

            $today = date('md');
            $pointsInfo = $this->getRoomPoints($playerid);
            if (empty($pointsInfo) == true)
            {
                break;
            }
            else {}

            $cacheObj = redis_cache::instance();
            $key = $this->_cache_room_points . $playerid;
            $fieldKey = 'jifen_' . $today;
            $cacheObj->hIncrBy($key, 'total_jifen', $points);
            $cacheObj->hIncrBy($key, $fieldKey, $points);
            //写入队列缓存
            $this->writeQueue($pointsInfo, $playerid);
            break;
        }
        return $pointsInfo;
    }
    
    /**
     * 读取房间积分
     * @param string $userid
     * @param string $reset
     * @return array|number[]|string[]
     */
    public function getRoomPoints($userid, $reset = false)
    {
        $cacheObj = redis_cache::instance();
        $key = $this->_cache_room_points . $userid;
        $today = date('md');
        $pointsRet = [];

        while (true)
        {
            $fieldKey = 'jifen_' . $today;
            //从缓存中获取积分
            if ($reset == false && $cacheObj->exists($key))
            {
                if ($cacheObj->hExists($key, 'total_jifen'))
                {
                    $pointsRet['total_jifen'] = $cacheObj->hMget($key, 'total_jifen')['total_jifen'];
                }
                else
                {
                    $pointsRet['total_jifen'] = 0;
                }

                if ($cacheObj->hExists($key, $fieldKey))
                {
                    $pointsRet['day_jifen'] = $cacheObj->hMget($key, $fieldKey)[$fieldKey];
                }
                else
                {
                    $pointsRet['day_jifen'] = 0;
                }
                $pointsRet['today'] = $today;
                //$pointsRet = $cacheObj->get_object($key);
            }
            else {}

            //获取到数据
            if (empty($pointsRet) == false)
            {
                break;
            }
            else {}
            
            $where = [
                'userid'=> $userid,
            ];
            //从缓存中获取数据
            $pointsRet = $this->infoBy($where, 'id,today,total_jifen,day_jifen');
            if (empty($pointsRet) == true) //数据为空初始化
            {
                $pointsRet = [];
                break;
            }
            else {}
            //写入缓存
           /* $cacheObj->set_object($key, $pointsRet);*/

            //写入中
            $cacheObj->hSet($key, 'total_jifen', $pointsRet['total_jifen']);
            //写入当天积分
            if ($pointsRet['today'] == $today)
            {
                $cacheObj->hSet($key, $fieldKey, $pointsRet['day_jifen']);
            }
            else
            {
                $cacheObj->hSet($key, $fieldKey, 0);
            }
            $cacheObj->expire($key, $this->_cache_expire_time);
            break;
        }
        if (empty($pointsRet) == false)
        {
            if ($pointsRet['today'] != $today)
            {
                $pointsRet['day_jifen'] = 0;
            }
            else {}
        }
        return $pointsRet;
    }

    /**
     * 写入积分钻石亲密度缓存
     * @param $room_id
     * @param $pointsKey
     * @param $total
     * @param $commision
     */
    public function updateRoomDetail($room_id, $pointsKey, $total, $commision)
    {

        while (true)
        {
            $roomArr = $this->batchRoominfo([$room_id]);
            if (!isset($roomArr[$room_id]))//如果未找到不写入
            {
                break;
            }

            $covespoints = $total * $commision;
            $roominfo = $roomArr[$room_id];
            if (isset($roominfo[$pointsKey]))//如果存在则写入累计积分或钻石
            {
                $covespoints = $roominfo[$pointsKey] + $covespoints;
            }

            if(isset($roominfo['intimacy']))//如果存在则每次写入亲密度
            {
                $total = $roominfo['intimacy'] + $total;
            }

            $roominfo[$pointsKey] = $covespoints;
            $roominfo['intimacy'] = $total;
            $redis          = redis_cache::instance();
            $keyCache       = $this->_roominfoCache;//写入缓存
            $redis->hMset_serialize($keyCache, [$room_id => $roominfo]);
            break;
        }
    }
}
