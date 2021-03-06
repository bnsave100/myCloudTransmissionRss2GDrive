<?php

require 'gdrive.php';
require 'transmission.php';

				//Google Drive

// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Drive($client);

$targetFolderId='<googledrivefolderid>';  //use list function to reveal id of a folder already created or create a new one the first time


		//TESTS
//listFiles($service);
//listFolders($service);

//$folderName="provaCartella";
//createFolder($service,$folderName);



				// Transmission rpc parameters

$rss = array( //all rss goes here,separated by a comma
    'https://url-to-rss'
);

$server = 'http://x.x.x.x'; //local ip of wd my cloud nas
$port = 9092;  //rpc port
$rpcPath = '/transmission/rpc'; //default config
$user = '<user>';
$password = '<password>';  //user-password: default blank

$stash = '/mnt/HD/HD_a2/transmission-rss';   //FOLDER FOR DOWNLADED TORRENT'S METADATA. NEVER DELETE THIS FOLDERS OR THE FILES IN THIS FOLDER
!file_exists($stash) && mkdir($stash, 0777, true);

$trans = new Transmission($server, $port, $rpcPath, $user, $password);



				// PROGRAM
if ((json_decode($trans->freespace())->arguments->{'size-bytes'})>32212254720){ //avoid things to get fucked up adding torrents that will exceed free space available. required at least x bytes of free space 
			  //RSS MONITORING (IF NEW TORRENT FOUND, ADD IT TO TRANSMISSION)
$torrents = $trans->getRssItems($rss); //filter inside this function called (add to transmission only if matched specific strings)
foreach ($torrents as $torrent) {
		$lock_file = $stash.'/'.base64_encode($torrent['guid']);
		if (file_exists($lock_file)) {
			//printf("%s: skip add: %s\n", date('Y-m-d H:i:s'), $torrent['title']);
			continue;
		}
		
		$response = json_decode($trans->add($torrent['link']));
		
		if ($response->result == 'success') {
			file_put_contents($lock_file, '1');
			//printf("%s: success add: %s\n", date('Y-m-d H:i:s'), $torrent['title']);
		}
	} 
}




           //TORRENT MONITORING (IF FINISHED,UPLOAD TO DRIVE FOLDER)
if(!file_exists('/tmp/torrent.lock')){ // put this on /tmp for unattended shutdown fix
	
	if (file_exists('resumableupload.json')){ //first check if there is a file upload that need to be resumed
		file_put_contents("/tmp/torrent.lock",'1');	 //avoid other uploads until this one is finished
		resumeToFolder($client,$service);
	}	
	else { //otherwhise, check torrent list
		
		$obj=json_decode($trans->status());
	
		$torrentCount=($obj->arguments->{'current-stats'}->{'filesAdded'}>$obj->arguments->torrentCount)?$obj->arguments->{'current-stats'}->{'filesAdded'}:$obj->arguments->torrentCount; //fix for cycle mismatch (torrent id over torrenCount on torrent removal)
		
		//for($i=1; $i<=$obj->arguments->torrentCount; $i++) {
		for($i=1; $i<=$torrentCount; $i++) {	
			
			$obj2=json_decode($trans->torrentStatus($i));
			
			if($obj2->arguments->torrents['0']->percentDone==1) { //download torrent completed?
				
				$mimeType=mime_content_type('/mnt/HD/HD_a2/<transmission_download_dir>/'.$obj2->arguments->torrents['0']->name);
				
				if($mimeType==='directory'){
					uploadFolder($client,$service,$targetFolderId,$obj2->arguments->torrents['0']->name);
				}
				else
				{
					$searchCheck=searchFile($service,$targetFolderId,$obj2->arguments->torrents['0']->name);	
					if((count($searchCheck)==0) and (((strpos($obj2->arguments->torrents['0']->name,'<torrent_search_string_1>'))!==false) or ((strpos($obj2->arguments->torrents['0']->name,'<torrent_search_string_2>'))!==false))) {
						
						if($obj2->arguments->torrents['0']->totalSize<=268435456) {
							uploadToFolder($service,$targetFolderId,'',$obj2->arguments->torrents['0']->name);		
					    }
						else {
							file_put_contents("/tmp/torrent.lock",'1'); //avoid other uploads until this one is finished
							uploadLargeToFolderResumable($client,$service,$targetFolderId,$obj2->arguments->torrents['0']->name); //start a new big file resumable upload
						}
						
												}
						else {
							//print("file already uploaded \n");
						}
				
				}
				//ratio policy can go eventually here
				// ... $trans->torrentRemove($obj2->arguments->torrents['0']->id);
			}
		}
	}
	
	}
else {
			//print("upload of big file in progress \n");
}
?>
