<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

define('QOS_LIMIT',10);
$worker = 10;
$renewalworker = 10;
$reprocessworker = 20;
define('WORKER',$worker);
define('RETRY_WORKER',$reprocessworker);
define('WELCOME_SMS','ZAWADI KUU ni hadi Tsh.5,000,000!DROO ya wiki Tsh.1,000,000!DROO ya SIKU Tsh.100,000!Jibu sahihi point 100 na lisilo sahihi ni 25!CHEZA ZAIDI USHINDE ZAIDI!');

//define('UNSUB_SMS','Nafasi uliyonayo inaridhisha!USIIACHE fursa ya kushinda zawadi za jumla ya Sh 5,000,000. Kujiunga tena tuma STAR kwenda. 15633. Ukicheza zaidi LAZIMA utashinda!');
define('UNSUB_SMS','Wewe ni mmoja wa wachezaji BORA!Usipoteze fursa ya kushinda hadi Tsh.11,500,000!KUJIUNGA tena tuma STAA kwenda 15633.CHEZA ZAIDI USHINDE ZAIDI!');
//define('LOW_BALANCE','Hauna salio la kutosha kuendelea kujibu maswali. Tafadhali ongeza salio kwenye akaunti yako na uendele kuongeza pointi ili USHINDE hadi Tsh 10,000,000!');
//define('LOW_BALANCE','Salio lako limeisha! Ongeza salio sasa kisha jibu UBAHATIKE na USHINDI wa sehemu ya Tsh 5,600,000. Kuwa mmoja wa MAMILIONEA wa 2021 sasa!');
define('LOW_BALANCE','Ndugu mteja hauna salio la kutosha kucheza ISHI KISTAA, tafadhali ongeza salio kisha tuma JIBU LAKO tena.Pole kwa usumbufu, bado USHINDI UKO MIKONONI MWAKO!!');
define('FIRST_APPEND','Jibu swali ushinde zaidi ya 12,000,000 msimu huu. Anza sasa!');
define('NON_CUSTOMER','Hujajiunga na huduma ya ISHI KISTAA!Jiunge SASA Ushinde hadi Tsh.11,500,000!Tsh.1,000,000 KILA WIKI!Tuma neno STAA kwa Tsh.99/SMS!CHEZA ZAIDI USHINDE ZAIDI!');
define('ERROR_MSG','Ndugu Mteja, Tafadhali tuma jibu lako tena. Asante.');
define('QN_ERROR_MSG','Una Pointi %s. Pointi zako zitabadilishwa muda mfupi. ');
define('POINT_MSG','Point zako ni %s. Cheza USHINDE droo ya siku, wiki na ZAWADI KUU ya Tsh.5,000,000! Jishindie ZAWADI hadi Tsh.11,500,000!CHEZA ZAIDI USHINDE ZAIDI!!');
/*define('HELP_MSG','Shinda zawadi hadi Tsh.11,500,000!Droo ya wiki Tsh. 1,000,000!Droo ya Siku Tsh.100,000!Jibu Sahihi point 100 na Lisilo sahihi 25!CHEZA ZAIDI USHINDE ZAIDI!');*/
define('HELP_MSG','Jibu maswali ya burudani kwa Tsh99/sms, kusanya ALAMA ubahatishe hadi Tsh 50,000,000. Jibu sahihi unapata ALAMA 100, lisilo sahihi ALAMA 25. Kujitoa tuma ONDOA.');
define('POINT_APPEND','Una pointi %s!');
define('ONDOA','UNAKOSA MAMILIONI ya sehemu ya Tsh 4,900,000. Umejitoka katika Ishi Kistaa. Kujiunga tena, tuma neno STAA kwenda 15633 USHINDE!');
define('QN_END_APPEND','Tafadhali jibu tuma A au B.');
define('LOW_BALANCE_QN_MSG','Umebakisha POINT chache tu USHINDE sehemu ya Tsh 4,900,000 sasa! ONGEZA salio JIBU ushinde sehemu ya Tsh 2,400,000 ya ZAWADI za KILA WIKI!Umebakisha POINT chache tu USHINDE sehemu ya Tsh 4,900,000 sasa! ONGEZA salio JIBU ushinde sehemu ya Tsh 2,400,000 ya ZAWADI za KILA WIKI!');
define('GOLDEN_HR_START','18');
define('GOLDEN_HR_END','21');

define('MIN_QN_ID',1);
define('MAX_QN_ID',1965);

define('PURCHASE','Hongera! Umefanikiwa kununua POINT %point,salio la point zako ni %totalpoints. Endelea kununua point ujiongezeee nafasi ya ushindi kwenye droo Tsh.5,000,000!');
define('LOW_BALANCE_PURCHASE','Ndugu Mteja hujafanikiwa kununua Point, tafadhali angalia salio lako na ujaribu tena. Usipoteze nafasi ya Ushindi wa hadi Tsh.5,000,000!');

define('NO_SUB_OPEN_QN_NOTIF','No open question for the subscriber');
define('NO_SUB_INT_REQ_NOTIF','no interactive request sent to subscriber today');
define('OPEN_QN_CONTENT','Hongera sana umejikusanyia alama %s hadi sasa. Ongeza alama kidogo tu unaweza kubahatika na sehemu ya Tsh 4,500,000;');

define('POINT_PURCHASE_MENU','Kununua ALAMA tafadhali tuma,
P300 Kwa Tsh300.
P600 Kwa Tsh500.
P1500 Kwa Tsh1,000.
P2500 Kwa Tsh2,000.
P10000 Kwa Tsh5,000.
');

define('POINT_PURCHASE_MAP', ["P300" => 300,"P600" => 500,"P1500" => 1000,"P2500" => 2000,"P10000" => 5000,]);
