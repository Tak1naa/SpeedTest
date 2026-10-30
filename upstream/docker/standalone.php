<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta charset="UTF-8" />
<link rel="shortcut icon" href="favicon.ico">
<script type="text/javascript" src="speedtest.js"></script>
<script type="text/javascript">
function I(i){return document.getElementById(i);}
// Native dialogs provide focus containment, Escape and focus restoration.
function showInfoDialog(id){
    if(id==='coffeeDialog'){
        var image=I('coffeeQr');
        if(!image.getAttribute('src')) image.src=image.dataset.src;
    }
    I(id).showModal();
}
//INITIALIZE SPEEDTEST
var s=new Speedtest(); //create speedtest object
s.setParameter("time_dl_max",10);
s.setParameter("time_ul_max",10);
s.setParameter("test_order","P_D_U");
s.setParameter("getIp_ispInfo",false);
s.setParameter("xhr_ignoreErrors",0);
// Current Firefox exposes same-origin Worker resource timing. Explicitly use
// the upstream precise timer; its existing fallback covers missing/zero entries.
s.setParameter("ping_allowPerformanceApi",true);
function finishSession(){
    // Never issue an owner cleanup from an idle page; deduplicate onend/pagehide.
    if(finishing) return finishing;
    if(!sessionOwned) return Promise.resolve();
    finishing=fetch('backend/getIP.php', {method:'POST', headers:{'X-Speedtest-Action':'finish'}, keepalive:true}).then(function(response){
        if(response.status!==204) throw new Error('Session release not confirmed');
        sessionOwned=false;
    }).catch(function(){
        // Do not start another test while the old lock/cookie may still exist.
        announce('未能确认测速会话已结束，请刷新页面或稍后重试。 / Session release was not confirmed. Reload the page or try later.');
    }).then(function(){
        finishing=null;
        if(releaseWaitAnnounced && !sessionOwned) announce('上次会话已结束，可以再次开始测速。 / Previous session ended. You can start a new test.');
        releaseWaitAnnounced=false;
    });
    return finishing;
}
var nativeStart=s.start, admitting=false, admissionCancelled=false, admittedIp='';
var sessionOwned=false, finishing=null, releaseWaitAnnounced=false;
// Announce phase changes, not every numeric frame, to avoid screen-reader chatter.
var announcedPhase=null;
function announce(message){I('testStatus').textContent=message;}
// Admission happens before the unchanged upstream Worker starts measuring.
s.start=function(){
    if(admitting) return;
    if(finishing || sessionOwned){
        I('startStopBtn').className='';
        releaseWaitAnnounced=Boolean(finishing);
        announce(finishing ? '正在结束上次会话，请稍后再开始。 / Finishing the previous session. Please wait.' : '上次会话释放未确认，请刷新页面或稍后重试。 / Previous session release is unconfirmed. Reload the page or try later.');
        return;
    }
    admitting=true; admissionCancelled=false;
    announcedPhase=null;
    announce('正在申请测速会话。 / Requesting a test session.');
    fetch('backend/getIP.php', {cache:'no-store', headers:{'X-Speedtest-Action':'start'}}).then(function(response){
        if(!response.ok){
            var messages={
                403:'无法开始测速：会话或访问权限无效，请重新打开页面。 / Cannot start: invalid session or access denied. Reload the page.',
                429:'暂时无法开始测速：已有会话，或触发来源、网络或访问频率限制，请稍后重试。 / Cannot start: a test is active or a source, network or request-rate limit was reached. Retry later.',
                503:'测速保护服务暂时不可用，请稍后重试。 / Test protection is unavailable. Try again later.'
            };
            throw new Error(messages[response.status] || '测速服务异常，请稍后重试。 / The test service returned an error. Try again later.');
        }
        // The response has issued the HttpOnly cookie even if JSON decoding fails.
        sessionOwned=true;
        return response.json();
    }).then(function(data){
        admitting=false;
        if(admissionCancelled){finishSession().catch(function(){});return;}
        admittedIp=data.processedString || '';
        nativeStart.call(s);
        scheduleFrame();
    }).catch(function(error){
        admitting=false;
        if(sessionOwned) finishSession();
        I('startStopBtn').className='';
        I('ip').textContent=error instanceof TypeError ? '无法连接测速服务，请检查网络后重试。 / Cannot connect to the test service. Check your network and try again.' : error.message;
        announce(I('ip').textContent);
    });
};
window.addEventListener('pagehide',function(){
    // A pending admission may succeed after navigation; never start its Worker.
    if(admitting) admissionCancelled=true;
    finishSession();
});
<?php if(getenv("TELEMETRY")=="true"){ ?>
s.setParameter("telemetry_level","basic");
<?php } ?>
<?php if(getenv("DISABLE_IPINFO")=="true"){ ?>
s.setParameter("getIp_ispInfo",false);
<?php } ?>
<?php if(getenv("DISTANCE")){ ?>
s.setParameter("getIp_ispInfo_distance","<?=getenv("DISTANCE") ?>");
<?php } ?>

var meterBk=/Trident.*rv:(\d+\.\d+)/i.test(navigator.userAgent)?"#EAEAEA":"#80808040";
var dlColor="#6060AA",
	ulColor="#616161";
var progColor=meterBk;

//CODE FOR GAUGES
function drawMeter(c,amount,bk,fg,progress,prog){
	var ctx=c.getContext("2d");
	var dp=window.devicePixelRatio||1;
	// Canvas bitmap dimensions are integers. Fractional DPR otherwise makes
	// the equality test fail and resets both bitmaps on every frame.
	var cw=Math.floor(c.clientWidth*dp), ch=Math.floor(c.clientHeight*dp);
	var sizScale=ch*0.0055;
	if(c.width==cw&&c.height==ch){
		ctx.clearRect(0,0,cw,ch);
	}else{
		c.width=cw;
		c.height=ch;
	}
	ctx.beginPath();
	ctx.strokeStyle=bk;
	ctx.lineWidth=12*sizScale;
	ctx.arc(c.width/2,c.height-58*sizScale,c.height/1.8-ctx.lineWidth,-Math.PI*1.1,Math.PI*0.1);
	ctx.stroke();
	ctx.beginPath();
	ctx.strokeStyle=fg;
	ctx.lineWidth=12*sizScale;
	ctx.arc(c.width/2,c.height-58*sizScale,c.height/1.8-ctx.lineWidth,-Math.PI*1.1,amount*Math.PI*1.2-Math.PI*1.1);
	ctx.stroke();
	if(typeof progress !== "undefined"){
		ctx.fillStyle=prog;
		ctx.fillRect(c.width*0.3,c.height-16*sizScale,c.width*0.4*progress,4*sizScale);
	}
}
function mbpsToAmount(s){
	return 1-(1/(Math.pow(1.3,Math.sqrt(s))));
}
function format(d){
    d=Number(d);
    if(d<10) return d.toFixed(2);
    if(d<100) return d.toFixed(1);
    return d.toFixed(0);
}

//UI CODE
var uiData=null;
function startStop(){
    if(admitting){
        admissionCancelled=true;
        I('startStopBtn').className='';
        announce('已取消申请。 / Session request cancelled.');
        return;
    }
    if(s.getState()==3){
		//speedtest is running, abort
		s.abort();
		uiData=null;
		I("startStopBtn").className="";
		initUI();
		announce('测速已取消。 / Test cancelled.');
	}else{
		//test is not running, begin
		I("startStopBtn").className="running";
		I("shareArea").style.display="none";
		s.onupdate=function(data){
            uiData=data;
		};
		s.onend=function(aborted){
            finishSession().catch(function(){});
            I("startStopBtn").className="";
            updateUI(true);
            announce(aborted ? '测速已取消。 / Test cancelled.' : '测速完成。');
            if(!aborted && uiData){
                announce('测速完成：下载 '+format(uiData.dlStatus)+' Mbps，上传 '+format(uiData.ulStatus)+' Mbps，延迟 '+format(uiData.pingStatus)+' ms，抖动 '+format(uiData.jitterStatus)+' ms。');
            }
            if(!aborted && uiData && (Number(uiData.dlStatus)<=0 || Number(uiData.ulStatus)<=0)){
                I('ip').textContent='测速未完整完成：请检查服务、会话超时或流量额度后重试。 / Test incomplete: check service availability, session timeout or traffic limits before retrying.';
                announce(I('ip').textContent);
            }
            if(!aborted){
                //if testId is present, show sharing panel, otherwise do nothing
                try{
                    var testId=uiData.testId;
                    if(testId!=null){
                        var shareURL=window.location.href.substring(0,window.location.href.lastIndexOf("/"))+"/results/?id="+testId;
                        I("resultsImg").src=shareURL;
                        I("resultsURL").value=shareURL;
                        I("testId").innerHTML=testId;
                        I("shareArea").style.display="";
                    }
                }catch(e){}
            }
		};
		s.start();
	}
}
//this function reads the data sent back by the test and updates the UI
function updateUI(forced){
	if(!forced&&s.getState()!=3) return;
	if(uiData==null) return;
	var status=uiData.testState;
	if(s.getState()==3 && announcedPhase!==status){
        var phases={1:'正在测量下载。 / Measuring download.',2:'正在测量延迟和抖动。 / Measuring ping and jitter.',3:'正在测量上传。 / Measuring upload.'};
        if(phases[status]) announce(phases[status]);
        announcedPhase=status;
    }
	I("ip").textContent='客户端 IP / Client IP: ' + (uiData.clientIp || admittedIp);
	I("dlText").textContent=(status==1&&uiData.dlStatus==0)?"...":format(uiData.dlStatus);
	drawMeter(I("dlMeter"),mbpsToAmount(Number(uiData.dlStatus*(status==1?oscillate():1))),meterBk,dlColor,Number(uiData.dlProgress),progColor);
	I("ulText").textContent=(status==3&&uiData.ulStatus==0)?"...":format(uiData.ulStatus);
	drawMeter(I("ulMeter"),mbpsToAmount(Number(uiData.ulStatus*(status==3?oscillate():1))),meterBk,ulColor,Number(uiData.ulProgress),progColor);
	I("pingText").textContent=format(uiData.pingStatus);
	I("jitText").textContent=format(uiData.jitterStatus);
}
function oscillate(){
	if(window.matchMedia('(prefers-reduced-motion: reduce)').matches) return 1;
	return 1+0.02*Math.sin(Date.now()/100);
}
// Render only while measuring; no permanent idle animation loop or Worker changes.
window.requestAnimationFrame=window.requestAnimationFrame||window.webkitRequestAnimationFrame||window.mozRequestAnimationFrame||window.msRequestAnimationFrame||(function(callback,element){setTimeout(callback,1000/60);});
var pendingFrame=false;
function scheduleFrame(){
    if(!pendingFrame){pendingFrame=true;requestAnimationFrame(frame);}
}
function frame(){
	pendingFrame=false;
	updateUI();
	if(s.getState()==3) scheduleFrame();
}
window.addEventListener('resize',function(){
    if(s.getState()!=3){if(uiData) updateUI(true);else initUI();}
});
//function to (re)initialize UI
function initUI(){
	drawMeter(I("dlMeter"),0,meterBk,dlColor,0);
	drawMeter(I("ulMeter"),0,meterBk,ulColor,0);
	I("dlText").textContent="";
	I("ulText").textContent="";
	I("pingText").textContent="";
	I("jitText").textContent="";
	I("ip").textContent="";
}
</script>
<style type="text/css">
	html,body{
		border:none; padding:0; margin:0;
		background:#FFFFFF;
		color:#202020;
	}
	body{
		text-align:center;
		font-family:system-ui,-apple-system,"Segoe UI","Roboto","Noto Sans CJK SC","Microsoft YaHei",sans-serif;
		font-size:16px;
		line-height:1.5;
		font-kerning:normal;
		letter-spacing:0.01em;
		padding:32px 16px;
	}
	h1{
		color:#404040;
		font-size:28px;
		margin:0 0 24px;
		line-height:1.3;
	}
	h1 .englishTitle{
		display:block;
		font-size:16px;
		font-weight:400;
		margin-top:8px;
		color:#616161;
	}
	#startStopBtn{
		display:inline-block;
		margin:0 auto;
		color:#6060AA;
		background-color:rgba(0,0,0,0);
		border:0.15em solid #6060FF;
		border-radius:0.3em;
		transition:all 0.3s;
		box-sizing:border-box;
		width:auto; min-width:min(12em,100%); max-width:100%; min-height:3em;
		padding:0.5em 1em;
		font:inherit;
		line-height:1.4;
		cursor:pointer;
		box-shadow: 0 0 0 rgba(0,0,0,0.1), inset 0 0 0 rgba(0,0,0,0.1);
	}
	#startStopBtn:hover{
		box-shadow: 0 0 2em rgba(0,0,0,0.1), inset 0 0 1em rgba(0,0,0,0.1);
	}
	#startStopBtn.running{
		background-color:#B82020;
		border-color:#B82020;
		color:#FFFFFF;
	}
	#startStopBtn .abortLabel, #startStopBtn.running .startLabel{
		display:none;
	}
	#startStopBtn.running .abortLabel{
		display:inline;
	}
	#startStopBtn:focus-visible{
		outline:2px solid #6060AA;
		outline-offset:3px;
	}
	#ipArea{
		max-width:40em;
		margin:0.5em auto;
		padding:0 1em;
		overflow-wrap:anywhere;
	}
    /* Keep admission errors beside the action, not below the mobile gauges. */
    .testStatus{max-width:60ch;margin:12px auto 0;padding:0 8px;font-size:1rem;line-height:1.5;overflow-wrap:anywhere;}
    footer{max-width:640px;margin:24px auto;padding:0 16px;line-height:1.6;overflow-wrap:anywhere;}
    footer p{margin:8px 0;}
    footer a{display:inline-flex;align-items:center;min-height:44px;}
    .infoLink{font:inherit;color:#5753a5;background:transparent;border:0;padding:8px 12px;min-height:44px;cursor:pointer;text-decoration:underline;text-underline-offset:3px;}
    .infoLink:focus-visible,.dialogClose:focus-visible{outline:2px solid #5753a5;outline-offset:3px;}
    .serviceNotice{margin:4px auto 0;}
    .coffeeSupport{margin-top:20px;}
    .supportPurpose{color:#555;font-size:0.95rem;}
    dialog.infoDialog{box-sizing:border-box;width:min(560px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow:auto;padding:24px;border:1px solid #ddd;border-radius:12px;background:#fff;color:#222;box-shadow:0 16px 48px rgba(0,0,0,0.22);text-align:left;line-height:1.65;}
    dialog.infoDialog::backdrop{background:rgba(0,0,0,0.4);}
    .dialogHeader{display:flex;align-items:center;justify-content:space-between;gap:16px;}
    .dialogHeader h2{font-size:1.25rem;margin:0;}
    .dialogClose{font:inherit;min-height:44px;min-width:60px;padding:8px 12px;cursor:pointer;background:#fff;color:#333;border:1px solid #aaa;border-radius:8px;}
    .infoDialog h3{font-size:1rem;margin:24px 0 8px;}
    .infoDialog p,.infoDialog ul{margin:8px 0;}
    .infoDialog a{color:#5753a5;text-underline-offset:3px;}
    .coffeeQr{display:block;width:min(100%,320px);height:auto;margin:16px auto;}
    /* Keep the title truly centered, independently of the close control. */
    #coffeeDialog{text-align:center;}
    #coffeeDialog .dialogHeader{display:grid;grid-template-columns:minmax(0,1fr);gap:8px;}
    #coffeeDialog .dialogClose{grid-row:1;justify-self:end;}
    #coffeeDialog h2{grid-row:2;line-height:1.4;}
    #coffeeDialog p{margin:12px auto;text-wrap:pretty;}
    :lang(en){letter-spacing:0.015em;}
    .meterText{letter-spacing:0;}
    @media (prefers-reduced-motion:reduce){#startStopBtn{transition:none;}}
	#test{
		margin:32px auto 24px;
		max-width:640px;
	}
	div.testArea{
		display:flex;
		flex-direction:column;
		gap:8px;
		width:100%;
		max-width:100%;
		position:relative;
		box-sizing:border-box;
	}
	div.testArea2{
		display:flex;
		flex-direction:column;
		gap:4px;
		width:100%;
		max-width:100%;
		position:relative;
		box-sizing:border-box;
		text-align:center;
	}
	div.testArea div.testName{
		width:100%;
		font-size:18px;
		z-index:9;
	}
	div.testArea2 div.testName{
        display:block;
        text-align:center;
        font-size:18px;
	}
	div.testArea div.meterText{
		width:100%;
		font-size:clamp(32px,5vw,48px);
		line-height:1.1;
		font-variant-numeric:tabular-nums;
		z-index:9;
	}
	div.testArea2 div.meterText{
        display:inline-block;
        font-size:32px;
        line-height:1.2;
        font-variant-numeric:tabular-nums;
	}
	div.meterText:empty:before{
		content:"0.00";
	}
	div.testArea div.unit{
		width:100%;
		font-size:13px;
		color:#616161;
		z-index:9;
	}
	div.testArea2 div.unit{
		display:inline-block;
		font-size:13px;
		color:#616161;
	}
	.gaugeVisual{
		position:relative;
		width:100%;
	}
	/* Unit stays in normal flow outside the decorative canvas, including text zoom. */
	.gaugeCanvas{position:relative;width:100%;aspect-ratio:1.28;}
	.gaugeVisual>.unit{margin-top:6px;}
	.gaugeReading{
		position:absolute;
		top:49%;
		left:0;
		width:100%;
		display:flex;
		flex-direction:column;
		gap:6px;
	}
	div.testArea canvas{
		display:block;
		width:100%; height:100%;
		z-index:1;
	}
	div.testGroup{
		display:grid;
		grid-template-columns:repeat(2,minmax(0,1fr));
		gap:32px;
        margin: 0 auto 32px;
	}
	#shareArea{
		width:95%;
		max-width:40em;
		margin:0 auto;
		margin-top:2em;
	}
	#shareArea > *{
		display:block;
		width:100%;
		height:auto;
		margin: 0.25em 0;
	}
	@media all and (max-width:480px){
		body{padding:24px 16px;}
		h1{font-size:24px;}
		div.testGroup{gap:16px;}
		div.testGroup.speedGroup{grid-template-columns:1fr;max-width:280px;gap:24px;}
	}
</style>
<title><?= htmlspecialchars(getenv('TITLE') ?: 'Tak1na的自建测速', ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
<main>
<h1><?= htmlspecialchars(getenv('TITLE') ?: 'Tak1na的自建测速', ENT_QUOTES, 'UTF-8') ?></h1>
<noscript>测速需要启用 JavaScript。 / JavaScript is required to run a speed test.</noscript>
<div id="testWrapper">
	<button id="startStopBtn" type="button" onclick="startStop()"><span class="startLabel">开始测速 / Start</span><span class="abortLabel">取消测速 / Abort</span></button><br/>
    <div class="serviceNotice"><button id="privacyOpen" class="infoLink" type="button" aria-haspopup="dialog" aria-controls="privacyPolicy" onclick="showInfoDialog('privacyPolicy')">隐私和服务说明</button></div>
    <p id="testStatus" class="testStatus" role="status" aria-live="polite" aria-atomic="true">准备就绪，可开始测速。 / Ready to start.</p>
	<div id="test">
		<div class="testGroup">
			<div class="testArea2" role="group" aria-labelledby="pingLabel">
				<div id="pingLabel" class="testName">延迟 / Ping</div>
				<div id="pingText" class="meterText" style="color:#AA6060"></div>
				<div class="unit">毫秒 / ms</div>
			</div>
			<div class="testArea2" role="group" aria-labelledby="jitterLabel">
				<div id="jitterLabel" class="testName">抖动 / Jitter</div>
				<div id="jitText" class="meterText" style="color:#AA6060"></div>
				<div class="unit">毫秒 / ms</div>
			</div>
		</div>
		<div class="testGroup speedGroup">
			<div class="testArea" role="group" aria-labelledby="downloadLabel">
				<div id="downloadLabel" class="testName">下载 / Download</div>
				<div class="gaugeVisual">
				<div class="gaugeCanvas">
				<canvas id="dlMeter" class="meter" aria-hidden="true"></canvas>
				<div class="gaugeReading">
				<div id="dlText" class="meterText"></div>
				</div></div>
				<div class="unit">兆比特/秒 / Mbps</div>
				</div>
			</div>
			<div class="testArea" role="group" aria-labelledby="uploadLabel">
				<div id="uploadLabel" class="testName">上传 / Upload</div>
				<div class="gaugeVisual">
				<div class="gaugeCanvas">
				<canvas id="ulMeter" class="meter" aria-hidden="true"></canvas>
				<div class="gaugeReading">
				<div id="ulText" class="meterText"></div>
				</div></div>
				<div class="unit">兆比特/秒 / Mbps</div>
				</div>
			</div>
		</div>
		<div id="ipArea">
			<span id="ip"></span>
		</div>
		<div id="shareArea" style="display:none">
			<h3>分享结果 / Share results</h3>
			<p>测速编号 / Test ID: <span id="testId"></span></p>
			<input type="text" value="" id="resultsURL" aria-label="结果链接 / Result link" readonly="readonly" onclick="this.select();this.focus();this.select();document.execCommand('copy');alert('链接已复制 / Link copied')"/>
			<img src="" id="resultsImg" alt="测速结果 / Speed test results" />
		</div>
	</div>
</div>
</main>
<footer>
    <p>这里可以获得 <a href="https://github.com/Tak1naa/SpeedTest">本站源代码</a></p>
    <p>本测速服务器位于北京交通大学嘉园学生公寓</p>
    <p>其他测速站点： <a href="http://test.nju.edu.cn/">南京大学</a> / <a href="http://speed.nuaa.edu.cn/">南京航空航天大学</a> / <a href="https://test.ustc.edu.cn/">中国科学技术大学</a></p>
    <div class="coffeeSupport"><button id="coffeeOpen" class="infoLink" type="button" aria-haspopup="dialog" aria-controls="coffeeDialog" onclick="showInfoDialog('coffeeDialog')"><span lang="en">Buy me a coffee</span> / 请我喝杯咖啡</button><p class="supportPurpose">所有收入将用于服务器运维及升级。</p></div>
</footer>
<dialog id="privacyPolicy" class="infoDialog" aria-labelledby="privacyTitle">
    <div class="dialogHeader"><h2 id="privacyTitle">隐私和服务说明</h2><button class="dialogClose" type="button" onclick="I('privacyPolicy').close()" autofocus>关闭</button></div>
    <p>本站用于测量你的设备与本站服务器之间的网络表现。结果受 Wi-Fi、路由、校园网络及当前负载影响，不等同于运营商公网出口带宽。</p>
    <h3>处理哪些信息</h3>
    <ul>
        <li>本站未启用测速结果遥测、数据库或结果分享，不保存下载、上传、延迟、抖动的结果记录，也不查询运营商或地理位置。</li>
        <li>为提供连接和防止滥用，服务器会处理你的 IP 地址；Redis 临时保存来源 IP、启动时间、次数、随机会话令牌及本次流量与阶段状态，不写入持久化数据库。</li>
        <li>次数记录在滚动 12 小时窗口内使用；会话 Cookie 最长有效 60 秒，设置 HttpOnly、SameSite，并在 HTTPS 下设置 Secure。策略迁移期的旧计数会在最长 48 小时内自然过期，不再限制新的日流量。</li>
        <li>保护日志记录加盐的来源摘要、事件和时间，不记录原始 IP、Cookie 或令牌；日志保留最多七天，容量限制可能使其提前轮转。管理员登录等系统日志不属于测速结果日志。</li>
    </ul>
    <h3>服务限制与使用提示</h3>
    <ul>
        <li>每个 IP 滚动 12 小时最多启动 5 次，5 分钟内最多 2 次；全站同时仅允许一个测速会话。成功启动即计次，取消或失败不退还次数。</li>
        <li>没有全站日流量额度，但仍有单次流量上限、请求超时及阶段截止。超限或保护服务不可用时会拒绝高流量请求。</li>
        <li>测速可能传输大量数据并占用线路，请确认流量计费方式，避免影响其他人的网络使用。本站为个人维护的测试服务，不承诺持续可用或固定测速结果。</li>
    </ul>
    <h3>自愿支持</h3>
    <p>捐助完全自愿，不影响测速权限、次数或优先级。所有收入将用于服务器运维及升级。付款通过微信处理，本站不接收支付密码或银行卡信息；付款前请核对收款方。</p>
    <p>更新日期：2026年10月13日。维护与代码信息见 <a href="https://github.com/Tak1naa/SpeedTest">本站源码仓库</a>。</p>
</dialog>
<dialog id="coffeeDialog" class="infoDialog" aria-labelledby="coffeeTitle">
    <div class="dialogHeader"><h2 id="coffeeTitle" lang="en">Buy me a coffee</h2><button class="dialogClose" type="button" onclick="I('coffeeDialog').close()" autofocus>关闭</button></div>
    <p>感谢你的支持！所有收入将用于服务器运维及升级。</p>
    <p>支持完全自愿，不影响测速权限、次数或优先级。公开源码不包含个人收款码，下方仅为占位图，不能用于付款。</p>
    <img id="coffeeQr" class="coffeeQr" data-src="coffee-qr.svg" width="320" height="320" alt="收款二维码占位图，请自行配置" decoding="async" />
</dialog>
<script type="text/javascript">setTimeout(function(){initUI()},100);</script>
</body>
</html>
