<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/user_guide/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
$route['default_controller'] = 'Notification';
$route['404_override'] = '';
$route['notification/user-optin/(:any)'] = 'Notification/user_optin';
$route['notification/user-optout/(:any)'] = 'Notification/user_optout';
$route['notification/user-renewed/(:any)'] = 'Notification/user_renewed';
$route['notification/mt/dn/(:any)'] = 'Notification/mtdn';
$route['notification/mo/(:any)'] = 'Notification/mo';
$route['notification/subscription/charge/(:any)'] = 'Notification/subscription_charge';
$route['notification/soka-trivia/billing'] = 'SokaTrivia/notification';
$route['ishikistaa/notification'] = 'Ishikistaa/notification';
$route['notification/megapromo/tigopesa/collection'] = 'MegaPromoCollection/tigopesa';
$route['notification/megapromo/tigopesapush/testcollection'] = 'MegaPromoCollection/tigopesapush';
$route['notification/megapromo/tigopesapush/collection'] = 'MegaPromoCollection/tigopesapush';
$route['notification/megapromo/mixxpush/collection'] = 'MegaPromoCollection/tigopesapush';
$route['notification/soka-trivia/billing'] = 'SokaTrivia/notification';
$route['soka-trivia/notification'] = 'SokaTrivia/notification';
$route['soka-mpush/notification'] = 'SokaMpush/notification';
$route['ishikistaa-mpush/notification'] = 'IshikistaaMpush/notification';
$route['megapromo-mpush/notification'] = 'MegaPromoMpush/notification';
$route['boa-trivia/notification'] = 'BoaTrivia/notification';
$route['notification/megapromo'] = 'MegaPromo/notification';
$route['notification/megapromo-test'] = 'MegaPromo/processNotification';
$route['boli/notification'] = 'Boli/notification';
$route['test/123'] = 'SokaTrivia/testLogging';
$route['test/insert'] = 'TestInsert/index';
$route['somatrivia/notification'] = 'SomaTrivia/notification';
$route['soma-trivia/notification'] = 'SomaTrivia/notification';
$route['supa-trivia/notification'] = 'Supa/notification';
