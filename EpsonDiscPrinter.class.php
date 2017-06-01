<?php

$p = new EpsonDiskPrinter('192.168.1.55');
$p->versionCheck();

$p->makeCd('disk.iso', 'disk.prn', 'NEW 2 TEST COVER');

//var_dump($p->getServerMode());

//var_dump($p->getDiskStatus());
//var_dump($p->getJobs());
exit;

for($i = 0; $i < 32; $i++) {
	$info = $p->getDeviceInfo($i);
	if ($info === false) break;
	echo "\nDevInfo    for $i: ";
	var_dump($info);

	$info = $p->getDeviceInfoInt($i);
	if ($info !== false) {
		echo "DevInfoInt for $i: ";
		var_dump($info);
	}
	$info = $p->getDeviceStatus($i);
	if ($info !== false) {
		echo "DevStatus  for $i: ";
		var_dump(bin2hex($info));
	}
}

class EpsonDiskPrinter {
	private $ip;
	private $curl = array();
	private $version = null;

	public function __construct($ip) {
		$this->ip = $ip;
	}

	public function makeCd($iso, $cover, $name) {
		// obtain publish id
		$publish_id = $this->getPublishId();
		if (!$publish_id) return false;

		echo "Got publish id: $publish_id\n";

		// build info file
		$info = array(
			'Common' => array(
				'Version' => '0x01030000',
				'ID' => '',
				'PublisherID' => '{EPPUB20110303_1}',
				'Load' => 1, // where to load the CD from
				'Eject' => 3,
				'Count' => 1, // number of copies
				'Create' => time(),
				'Publisher' => 'USER',
				'Mode' => 0,
				'JobName' => $name,
				'SaveJobData' => 0, // set to 1 to keep
				'PublishID' => $publish_id,
			),
			'Write' => array(
				'KindOfWrite' => 2,
				'KindOfDisc' => 1,
				'WriteDataPath' => '',
				'WriteDataSize' => filesize($iso),
				'SpecificDataSize' => 0,
				'WriteSpeed' => '400', // 40X
				'Confirmation' => 0,
				'DeletionMode' => 0,
				'Finalize' => 1,
			),
			'Print' => array(
				'PrinterName' => 'EPSON PP-100NPRN',
				'PrintMode' => 0,
				'DataPath' => 'C:\\DATA\\foobar.prn',
				'DryingTime' => 0,
				'PrintQuality' => 0,
			),
		);
		$strinfo = '';
		foreach($info as $name => $subinfo) {
			$strinfo .= '['.$name."]\n";
			foreach($subinfo as $subname => $subdata) {
				$strinfo .= $subname.' = '.$subdata."\n";
			}
			$strinfo .= "\n";
		}

		// set job
		$res = $this->curl(80, 'webapp/NormalJobSetter', $strinfo);
		if ($res[0] != "\0") return false;

		// decode job id
		list(,$len) = unpack('n', substr($res, 1, 2));
		if ($len != 4) return false; // bad format, should return an int

		$job_id = strtolower(bin2hex(substr($res, 3, 4)));

		echo "Got job id: $job_id\n";

		// get the webdav password
		$res = $this->curl(80, 'webapp/UploadInfoGetter?serverjobid='.$job_id);
		if ($res[0] != "\0") return false;

		// decode password
		list(,$len) = unpack('n', substr($res, 1, 2));
		$webdav_password = substr($res, 3, $len);

		echo "Got webdav password: $webdav_password\n";

		// initialize a curl context with this password
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $job_id.':'.$webdav_password);

		if (!$this->curl_mkcol($ch, 'http://'.$this->ip.'/uploads/'.$job_id)) return false;

		echo "Created root webdav dir\n";
		
		// set status=5
		$this->curl(80, 'webapp/JobController', array('action' => 5, 'serverjobid' => $job_id));

		// upload (fake) xml diskinfo, let's hope it'll be happy with it (Confirmation=0)
		$fp = fopen('php://temp', 'r+');
		fwrite($fp, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?'.'>'."\n".'<FileList></FileList>');

		$this->curl_put($ch, 'http://'.$this->ip.'/uploads/'.$job_id.'/DiscInfo.xml', $fp);
		fclose($fp);

		// set status=4 (uploading)
		$this->curl(80, 'webapp/JobController', array('action' => 4, 'serverjobid' => $job_id));

		echo "Uploading iso\n";

		if (!$this->curl_put($ch, 'http://'.$this->ip.'/uploads/'.$job_id.'/DiscImage_DiscImage.iso', $iso)) return false;

		echo "Uploading cover\n";

		if (!$this->curl_put($ch, 'http://'.$this->ip.'/uploads/'.$job_id.'/PrnData_000.prn', $cover)) return false;

		echo "Committing job\n";

		// set status=0 (ready)
		$this->curl(80, 'webapp/JobController', array('action' => 0, 'serverjobid' => $job_id));

		return true;
	}

	public function curl_mkcol($ch, $url) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'MKCOL');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$res = curl_exec($ch);

		if ($res === false) return false;
		return true;
	}

	public function curl_put($ch, $url, $file) {
		if (is_string($file)) {
			$fp = fopen($file, 'r');
		} else {
			$fp = $file;
		}
		if (!$fp) return false;
		fseek($fp, 0, SEEK_END);
		$fp_size = ftell($fp);
		rewind($fp);

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_PUT, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_INFILE, $fp);
		curl_setopt($ch, CURLOPT_INFILESIZE, $fp_size);

		$res = curl_exec($ch);

		if ($res === false) return false;

		return true;
	}

	public function getPublishId() {
		$res = $this->curl(80, 'webapp/PublishIDGetter');

		if ($res[0] != "\0") return false;
		list(,$len) = unpack('n', substr($res, 1, 2));

		return (string)substr($res, 3, $len);
	}

	public function getDiskStatus() {
		$status = $this->getDeviceStatus(1);
		// 0010 8000 0000 8000 0032 0000 005a 0000 0066
		list(,$len) = unpack('n', $status);
		var_dump($len, strlen($status));
		if (strlen($status)+2 < $len) return false;

		$data = substr($status, 2, $len);

		$device = array();

		for($i = 0; $i < 3; $i++) {
			$device[] = unpack('nflags/nfilled', $data);
			$data = substr($data, 4);
		}

		return $device;
	}

	protected function curl($port, $url, $post = false) {
		if (isset($this->curl[$port])) {
			$ch = $this->curl[$port];
			curl_setopt($ch, CURLOPT_URL, 'http://'.$this->ip.':'.$port.'/'.$url);
		} else {
			$ch = curl_init('http://'.$this->ip.':'.$port.'/'.$url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$this->curl[$port] = $ch;
		}
		if (is_array($post)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
		} else if (is_string($post)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		} else {
			curl_setopt($ch, CURLOPT_POST, false);
		}
		return curl_exec($ch);
	}

	// this returns two values, meaning unknown
	public function getPrnPosNum() {
		$res = $this->curl(80, 'webapp/PrnPosNumGetter');
		if ($res[0] != "\0") return false;

		return unpack('nunknown1/Nunknown2', substr($res, 1));
	}

	// return 259 ? or 01+03 ?
	public function getServerMode() {
		$res = $this->curl(80, 'webapp/ServerModeGetter');
		if ($res === false) return false;
		$list = unpack('Nmode', $res);
		return $list['mode'];
	}

	public function versionCheck($version = '01010000') {
		$res = $this->curl(80, 'webapp/IFVersionChecker', array('ifversion' => $version));
		if ($res != "\0\0\0") return false;
		$this->version = $version;
		return true;
	}

	public function getDeviceInfo($type = 0) {
		if (is_null($this->version)) return false;

		$res = $this->curl(80, 'webapp/DevInfoGetter?'.http_build_query(array('ifversion' => $this->version, 'type' => $type)));
		if ($res[0] != "\0") return false;
		list(,$len) = unpack('n', substr($res, 1, 2));

		return (string)substr($res, 3, $len);
	}

	public function getDeviceInfoInt($type = 0) {
		if (is_null($this->version)) return false;

		$res = $this->curl(80, 'webapp/DevInfoIntGetter?'.http_build_query(array('ifversion' => $this->version, 'type' => $type)));
		if ($res[0] != "\0") return false;
		list(,$len) = unpack('n', substr($res, 1, 2));

		list(,$final) = unpack('N', (string)substr($res, 3, $len));
		return $final;
	}

	public function getDeviceStatus($type = 0) {
		if (is_null($this->version)) return false;

		$res = $this->curl(80, 'webapp/DevStatusGetter?'.http_build_query(array('ifversion' => $this->version, 'type' => $type)));
		if ($res[0] != "\0") return false;
		$res = substr($res, 1);

		return $res;
	}

	public function getJobs($completed = false) {
		$type = 1;
		if ($completed) $type = 2;
		if (is_null($this->version)) return false;

		$res = $this->curl(80, 'webapp/JobInfoGetter?'.http_build_query(array('ifversion' => $this->version, 'type' => $type)));

		if (substr($res, 0, 1) != "\0") return false;
		$res = substr($res, 1);

		$final = array();

		$c = 0;

		while(true) {
			if ($res === '') break;
			list(,$size) = unpack('n', substr($res, 0, 2));
			if ($size == 0) break;

			$size += 3;

			$data = substr($res, 0, $size);
			$res = (string)substr($res, $size);

			$data = substr($data, 2); // remove size

			$info = unpack('cstatus/cunknownzero1/nquantity/ncompleted', substr($data, 0, 6));
			$data = substr($data, 6);
			$len = ord(substr($data, 0, 1)); // length of user name in bytes
			$info['owner'] = iconv('UNICODE', 'UTF-8', substr($data, 1, $len));
			$data = substr($data, 130); // padding?

			$info = array_merge($info, unpack('nunknownagain/ncd_out/nunknownzero/nunknown160/nerrors', substr($data, 0, 10)));
			$data = substr($data, 10);

			list(,$len) = unpack('n', substr($data, 0, 2));
			$info['title'] = iconv('UNICODE', 'UTF-8', substr($data, 2, $len));

			$final[] = $info;
		}
		return $final;
	}
}

