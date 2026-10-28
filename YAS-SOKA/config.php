<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

define('QOS_LIMIT',10);
$worker = 30;
$renewalworker = 10;
$moretryworker = 10;
$reprocessworker = 10;
$sokaboomcreditworker = 10;
define('WORKER',$worker);
define('RETRY_WORKER',$reprocessworker);
define('WELCOME_SMS','ZAWADI KUU ni hadi Tsh.5,000,000!Kila wiki Tsh.1,000,000 na KILA SIKU Tsh.100,000!Jibu sahihi ni point 100 na lisilo sahihi ni 25!CHEZA ZAIDI USHINDE ZAIDI!');

define('WELCOME_SMS_2','Kabla ya kuanza kushiriki, unashauriwa kupitia Sheria na Masharti, Yanayopatikana kwenye Tovuti  ifatayo.  http://www.yassports.co.tz/terms-and-conditions');
define('UNSUB_SMS','Wewe ni mmoja wa wachezaji BORA!Usipoteze fursa ya kushinda hadi Tsh.20,000,000.KUJIUNGA TENA tuma SOKA kwenda 15670.CHEZA ZAIDI USHINDE ZAIDI!');
//define('LOW_BALANCE','UMEISHIWA SALIO! Ongeza FASTAA bado kidogo saana! UBAHATIKE kuwa MILIONEA wa sehemu ya Tsh 5,000,000 ama Tsh 1,000,000 LEO.');
define('LOW_BALANCE','Ndugu mteja hauna salio la kutosha kucheza SOKA, tafadhali ongeza salio kisha tuma JIBU LAKO tena.Pole kwa usumbufu, bado USHINDI UKO MIKONONI MWAKO!');
define('FIRST_APPEND','Una point %s. PAPO HAPO unabahatika na SOKA;');
define('NON_CUSTOMER','Hongera! Hii ni SOKA, MAMILIONI yanakungoja wewe! Shinda hadi Tsh 12,000,000 TASLIM! Sehemu ya Tsh 9,000,000 KILA SIKU na sehemu ya Tsh 6,000,000 KILA WIKI! Jibu ujumbe kwa neno SOKA USHINDE!');
define('ERROR_MSG','Ndugu Mteja, Tafadhali tuma jibu lako tena. Asante.');
define('QN_ERROR_MSG','Pointi %s. Pointi zako zitabadilishwa muda mfupi. ');
define('POINT_MSG','Point zako ni %s. Cheza sasa USHINDE droo ya siku, wiki na ZAWADI KUU ya Tsh.5,000,000! Jishindie ZAWADI za jumla ya Tsh.20,000,000!CHEZA ZAIDI USHINDE ZAIDI!');
define('HELP_MSG','Shinda hadi Tsh 100,000,000. Tsh 1,000,000/mwezi, Tsh 500,000/Wiki na Tsh 50,000/Siku. Jibu sahihi unapata ALAMA 100 lisilo sahihi ALAMA 25. Kujitoa tuma ONDOA.');
define('POINT_APPEND','Unapoint %s!');
define('ONDOA','Ndugu mteja, kujitoa katika huduma ya SOKA tuma ONDOA SOKA kwenda 15670.');
define('NON_CUSTOMER_SUBSCRIBER','Asante kwa kutuma SMS SOKA ! point %s Unaweza kushinda Tsh 12,000,000 TASLIM na sehemu ya Tsh 9,000,000 KILA SIKU. Anza sasa kwa kujibu swali kwa kutuma A au B');
define('QN_END_APPEND','Tafadhali jibu tuma A au B.');
define('LOW_BALANCE_QN_MSG','Una point %s Unakaribia KUSHINDA ila huna salio la kujibia maswali tafadhali ongeza.');
define('GOLDEN_HR_START','18');
define('GOLDEN_HR_END','23');
define('MIN_QN_ID',8647);
define('MAX_QN_ID',9717);

define('custom_log_threshold','DEBUG');
define('INACTIVE_SUB','Asante kwa ujumbe wako! Tuma neno SOKA rudi mchezoni ujizolee sehemu ya Tsh 20,300,000, Vocha na zawadi kibao kutoka Tigo Soka sasa hivi.');

define('BOOM_CREDIT_NOTIFY_SMS','Ndugu mteja, Umezawadiwa nafasi 1 ya kujibu swali la SOKA buree. Itumie fursa hii kujibu na uingie kwenye Droo ya sehemu ya Tsh 10,000,000 sasa.');

define('PURCHASE','Hongera! Umefanikiwa kununua POINT %point,salio la point zako ni %totalpoints. Endelea kununua point ujiongezeee nafasi ya ushindi kwenye droo Tsh.5,000,000!');
define('LOW_BALANCE_PURCHASE','Ndugu Mteja hujafanikiwa kununua Point, tafadhali angalia salio lako na ujaribu tena. Usipoteze nafasi ya Ushindi wa hadi Tsh.5,000,000!');

define('PREDICT_ERROR_MSG','Ndugu Mteja, Tafadhali tuma ubashiri wako tena. Asante.');
define('PREDICT_REQUEST_PREFIX','PD');
define('PREDICT_CHARGE_AMOUNT',99);
/** Score MO: chars between home/away goals (used in parser regex character class). */
define('PREDICT_SCORE_SEPARATORS','-,;:');
/** Subscriber-facing separator list for SMS hints. */
define('PREDICT_SCORE_SEPARATOR_HINT','- , ; au :');
/** Example score MOs (keep in sync with PREDICT_SCORE_SEPARATORS). */
define('PREDICT_SCORE_EXAMPLES','M2-1 au M2,1 au M2;1 au M2:1');
define('PREDICT_LIST_HEADER','Ubashiri wa leo:');
define('PREDICT_FIXTURE_CHOSEN','Asante kwa kuchagua mechi. Tuma ubashiri wako kwa mfano '.PREDICT_SCORE_EXAMPLES.'.');
define('PREDICT_INVALID_SCORE','Tuma ubashiri kwa mfano '.PREDICT_SCORE_EXAMPLES.' (tumia '.PREDICT_SCORE_SEPARATOR_HINT.' kati ya magoli).');
define('PREDICT_RECEIVED','Ndugu mteja, Ubashiri wako umepokelewa. Tutakujulisha matokeo baada ya mechi kumalizika.');
define('PREDICT_LOW_BALANCE','Ndugu Mteja huna salio la kutosha kubashiri. Tafadhali ongeza salio na ujaribu tena.');
define('PREDICT_INACTIVE_SUB','Tuma neno SOKA kwanza ujiunge na huduma ya SOKA ili kushiriki Ubashiri na Ushindi.');
define('PREDICT_NO_FIXTURES','Hakuna mechi za ubashiri leo. Tafadhali jaribu tena baadaye.');
define('PREDICT_INVALID_SLOT','Nambari ya mechi si sahihi. Tuma PREDICT kupata orodha ya mechi.');
define('PREDICT_SEND_PREDICT_FIRST','Tuma neno PREDICT kwanza ili kupata orodha ya mechi.');
define('PREDICT_AFTER_KICKOFF','Muda wa kutuma ubashiri umeisha kwa mechi hii.');
define('PREDICT_ALREADY_SUBMITTED','Tayari umeshatuma ubashiri kwa mechi hii. Chagua mechi nyingine.');
define('PREDICT_ALL_DONE','Umeshatuma ubashiri kwa mechi zote za leo. Asante!');
define('PREDICT_SESSION_EXPIRED','Kipindi chako kimeisha. Tuma PREDICT tena.');
define('PREDICT_WRONG_STATE','Tafadhali fuata hatua: PREDICT, kisha chagua mechi (mfano M2), kisha tuma matokeo (mfano '.PREDICT_SCORE_EXAMPLES.').');

define('NO_SUB_OPEN_QN_NOTIF','No open question for the subscriber');
define('NO_SUB_INT_REQ_NOTIF','no interactive request sent to subscriber today');
define('OPEN_QN_CONTENT','Hongera sana umejikusanyia alama %s hadi sasa. Ongeza alama kidogo tu unaweza kubahatika na sehemu ya Tsh 5,500,000;');

define('POINT_PURCHASE_MENU','Kununua ALAMA tafadhali tuma,
P300 Kwa Tsh300.
P600 Kwa Tsh500.
P1500 Kwa Tsh1,000.
P2500 Kwa Tsh2,000.
P10000 Kwa Tsh5,000.
');

define('POINT_PURCHASE_MAP', ["P300" => 300,"P600" => 500,"P1500" => 1000,"P2500" => 2000,"P10000" => 5000,]);