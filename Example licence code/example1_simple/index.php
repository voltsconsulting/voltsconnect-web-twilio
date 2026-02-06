<?php
	/**
	 * @since: 24/04/2019
	 * @author: Sarwar Hasan
	 * @version 1.0.0
	 */

	require_once 'VoltsConnectWeb-TwilioBase.php';
	$errorMessage="";
	$responseObj=null;
	$version="1.0.1";
	$licenseKey="";
	$adminEmail=""; // admin email
	//active license
	if(VoltsConnectWeb-TwilioBase::CheckLicense($licenseKey,$errorMessage,$responseObj,$version,$adminEmail)) {
		print_r($responseObj);
		/*
		$responseObj->is_valid;         //true
		$responseObj->expire_date;      //expiry date or "No Expiry"
		$responseObj->support_end;      //support end date or "No Support"
		$responseObj->license_title;    //License type title
		$responseObj->license_key;      //License code
		$responseObj->msg;              //Success message
		*/
	}else{
		echo $errorMessage;
	}

	//Any time you will get the response object by calling this method, but it must be call after CheckLicense call
	$licenseObj=VoltsConnectWeb-TwilioBase::GetRegisterInfo();
	print_r($licenseObj);
	/*
	$licenseObj->is_valid;         //true
	$licenseObj->expire_date;      //expiry date or "No Expiry"
	$licenseObj->support_end;      //support end date or "No Support"
	$licenseObj->license_title;    //License type title
	$licenseObj->license_key;      //License code
	$licenseObj->msg;              //Success message
	 */


	//for remove license form the app
	/*
	if(VoltsConnectWeb-TwilioBase::RemoveLicenseKey($errorMessage,$version)){
		echo "Removed";
	}else{
		echo $errorMessage;
	}
	*/








	//check update information
	$updateInformation=VoltsConnectWeb-TwilioBase::GetPluginUpdateInfo();
	if(!empty($updateInformation) && is_object($updateInformation)) {
		//you will get this kind of  property
		/*
		$updateInformation->version       = "1.0.1";
		$updateInformation->slug          = "product base";
		$updateInformation->name          = "Element Pack";
		$updateInformation->new_version   = "1.0.1"; //update version
		$updateInformation->requires      = "1.0.1";
		$updateInformation->url           = "";
		$updateInformation->download_link = "http://localhost/projects/wp503/wp-content/uploads/2019/04/qt-1.zip";
		$updateInformation->sections      = array(
			"description" => "Description text",
			"changelog" => "Changelog text",
			"you_custom_section2" => "you ustom section ",
			//...
		);
		$updateInformation->icons         = array(
			"high"=>"http://localhost/projects/wp503/wp-content/uploads/2019/04/icon.jpg"
		);
		$updateInformation->banners       = array(
			"high" => "http://localhost/projects/wp503/wp-content/uploads/2019/04/banner.jpg",
		);
		$updateInformation->banners_rtl   = array();
		$updateInformation->package       = "http://localhost/projects/wp503/wp-content/uploads/2019/04/qt-1.zip";
		*/
		//check the app version with app version if updated then download and process the $updateInformation->package file
	}