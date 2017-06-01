<?php

if (ini_get('memory_limit') != '512M') {
	system('php -d memory_limit=512M '.escapeshellarg($_SERVER['argv'][0]));
	exit;
}

// BUILD PRN FILE FOR EPSON
$file = new PrnBuilder('colortest');
$file->gen('test.prn');


class PrnBuilder {
	const PRN_INIT = '0000001b0140454a4c20313238342e340a40454a4c20202020200a1b401b401b285208000052454d4f544531544908000007db03070f190a4a530400000000004a480c000001020000000800279441161b0000000000001b0140454a4c20313238342e340a40454a4c20202020200a1b401b401b285208000052454d4f544531504d02000000544908000007db03070f190a495202000003534e0100004d49040000015b635553030000000155530300000100555303000002001b0000001b28470100011b28550500010101a0051b55001b28690100001b19311b28430400741b00001b2863080068faffff081b00001b28530800741b0000741b00001b284b020000021b28440400403850141b286d0100801b286502000013';
	const PRN_FINI = '1b401b285208000052454d4f5445314952020000024c4400001b0000001b401b285208000052454d4f5445314c4400004a450100001b000000';
	const PRN_MARGIN_TOP = -1432; // as defined in PRN_INIT

	private $planes;
	private $fp;
	private $length;
	private $cycle = 0;
	private $feed;

	public function __construct($base) {

		foreach(array(0 => 'black', 1 => 'magenta', 2 => 'cyan', 4 => 'yellow', 17 => 'light_magenta', 18 => 'light_cyan') as $plane => $color) {
			$this->planes[$plane] = imagecreatefrompng($base.'_'.$color.'.png');
			if (!$this->planes[$plane]) throw new Exception('Failed to load plane '.$color);
		}
		$this->length = imagesy($this->planes[0]);
	}

	public function gen($out) {
		$this->fp = fopen($out, 'w');
		if (!$this->fp) return false;

		// write init
		fwrite($this->fp, pack('H*', self::PRN_INIT));

		$this->feed = 0-self::PRN_MARGIN_TOP;
		$pos = 0;

		while($pos < $this->length) {
			echo "Printing at $pos\n";
			$this->writeLines((int)round($pos));
			$pos+=42.5; // 85
		}

		// finish
		fwrite($this->fp, "\x0c");// form feed
		fwrite($this->fp, pack('H*', self::PRN_FINI));
		fclose($this->fp);
		return true;
	}

	public function writeLines($pos) {
		// we need 180 lines starting $pos, skip one line each time
		$lines = array();
		for($i = 0; $i < 180; $i++) $lines[$i] = $this->getLine($pos+($i*4)); // spacing=4
		// detect global start/end
		$gstart = strlen($lines[0]['planes'][0])-1;
		$gend = 0;
		$offset = ($this->cycle++) & 1;

		foreach($lines as $line) {
			if (!$line['value']) continue; // skip empty line
			foreach($line['planes'] as $line) {
				// check how many \0 in front and at the end
				$start = 0;
				$end = strlen($line);
				while($line[++$start] === "\0") if ($start>=$gstart) break;
				while($line[--$end] === "\0") if ($end<=$gend) break;
				$gstart = min($gstart, $start);
				$gend = max($gend, $end);
			}
		}

		if ($gstart > $gend) {
			// empty set, let's not waste time
			$this->feed += 85;
			return;
		}

		// pending page feed, run it now
		if ($this->feed) {
			fwrite($this->fp, "\x1b(v\x04\x00".pack('V', $this->feed));
			$this->feed = 0;
		}

		$glen = $gend - $gstart;

		// cut & compress lines!
		$data = array();
		foreach($lines as $line) {
			foreach($line['planes'] as $plane => $line) {
				if (!isset($data[$plane])) $data[$plane] = '';
				$data[$plane] .= $this->compress(substr($line, $gstart, $glen));
			}
		}

		// output each plane
		foreach($data as $plane => $dat) {
			// move to horizontal position
			fwrite($this->fp, "\x1b($".pack('vV', 4, ($gstart*4)*2+$offset)); // 720dpi => 1440dpi
			// output print req
			fwrite($this->fp, "\x1bi".pack('CCCvv', $plane, 1, 2, $glen, 180).$dat);
			// feed
			fwrite($this->fp, "\x0d");
		}
		// output horizontal pos + 85
		$this->feed += 85;
	}

	public function compress($data) {
		// in $data, detect similar bytes, etc
		// if we need to send only one byte, use 00+byte
		// up to 127 bytes all different => 1~127
		// up to 127 times the same byte: -1~-127
		$res = '';

		$repeat = 0;
		$norepeat = '';

		while(strlen($data) > 1) {
			if (($data[0] == $data[1]) && ($repeat > 0)) {
				$repeat++;
				$data = substr($data, 1);
				continue;
			}
			if (strlen($norepeat) && ($data[0] == $data[1])) {
				// send norepeat stuff
				while(strlen($norepeat) > 0) {
					$dat = substr($norepeat, 0, 128);
					$res .= pack('c', strlen($dat)-1).$dat;
					$norepeat = (string)substr($norepeat, strlen($dat));
				}
				// go in repeat mode
				$repeat++;
				$data = substr($data, 1);
				continue;
			}

			if ($repeat && ($data[0] != $data[1])) { // end of repeat mode
				$repeat++;
				while($repeat) {
					$rep_max = min($repeat, 128);
					$res .= pack('c', 1-$rep_max).$data[0];
					$repeat -= $rep_max;
				}
				$data = substr($data, 1);
				continue;
			}

			if ($data[0] == $data[1]) {
				$repeat++;
			} else {
				$norepeat .= $data[0];
			}
			$data = substr($data, 1);
		}

		if (strlen($norepeat)) {
			$norepeat .= $data;
			while(strlen($norepeat) > 0) {
				$dat = substr($norepeat, 0, 128);
				$res .= pack('c', strlen($dat)-1).$dat;
				$norepeat = (string)substr($norepeat, strlen($dat));
			}
		} else {
			$repeat++;
			while($repeat) {
				$rep_max = min($repeat, 128);
				$res .= pack('c', 1-$rep_max).$data[0];
				$repeat -= $rep_max;
			}
		}
		return $res;
	}

	public function decompress($data) {
		// parse and decompress PackBits format until we fill $len bytes
		// infos: http://en.wikipedia.org/wiki/PackBits

		$res = '';
		$pos = 0;
		while($pos < strlen($data)) {
			list(,$c) = unpack('c', $data[$pos++]);
			if ($c == -128) continue; // "skip to next byte"
			if ($c >= 0) {
				$c+=1;
				$res .= substr($data, $pos, $c);
				$pos += $c;
				continue;
			}
			// negative: one byte repeated
			$c = 1-$c;
			$res .= str_repeat($data[$pos++], $c);
		}
		return $res;
	}

	public function getLine($num, $decr = true) {
		// get data for this line in each plane
		$res = array();
		$real_width = imagesx($this->planes[0]);
		$width = (int)ceil($real_width/4)*4; // 2 bits per point => 4 points per byte
		$total = 0;
		if ($num >= $this->length) {
			// out of the image, return a null line
			foreach($this->planes as $plane => $img) {
				$res[$plane] = str_repeat("\0", $width);
			}
			return array('planes' => $res, 'value' => $total);
		}

		foreach($this->planes as $plane => $img) {
			$tmp = '';
			for($i = 0; $i < $width; $i+= 4) {
				$tmp2 = 0;
				for($j = 0; $j < 4; $j++) {
					if ($i+$j >= $real_width) continue;
					$value = imagecolorat($img, $i+$j, $num);
					if (!$value) continue;
					if ($value > 5) {
						if ($decr) imagesetpixel($img, $i+$j, $num, $value-3);
						$value = 3;
					} elseif ($value > 3) {
						// 4 or 5
						if ($decr) imagesetpixel($img, $i+$j, $num, 2);
						$value -= 2;
					} else { // 2
						if ($decr) imagesetpixel($img, $i+$j, $num, 0);
					}
					// $value should be 2 or 3
					$total += $value;
					$tmp2 |= ($value << 6-($j*2));
				}
				$tmp .= pack('C', $tmp2);
			}
			$res[$plane] = $tmp;
		}
		return array('planes' => $res, 'value' => $total);
	}
}


