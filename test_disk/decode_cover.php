<?php

error_reporting(E_ALL & ~E_ALL);

$parser = new EpsonParser($_SERVER['argv'][1]?:'../disk.prn', true);

//foreach(array('black','cyan','magenta','yellow','light_cyan','light_magenta') as $color) {
foreach(array('black') as $color) {
	echo "Exporting $color\n";
	$parser->exportColor($color, 'disk_'.$color.'.png');
}

class EpsonParser {
	private $fp;
	private $img_buffer = array();
	private $pos_x = 0;
	private $pos_y = 0;
	private $size_x = 0;
	private $size_y = 0;
	private $margin_top = 0;
	private $debug;
	private $spacing; // horiz_calc & vert_calc

	public function __construct($file, $debug = false) {
		$this->debug = $debug;
		$this->fp = fopen($file, 'rb');
		if (!$this->fp) throw new Exception('Couldn\'t open file');

		$this->parse();
	}

	public function exportColor($color, $out) {
		if (!isset($this->img_buffer[$color])) return false;
		$data = $this->img_buffer[$color];

		// determine where the image "starts"
		$width = $this->size_x;
		$height = $this->size_y;
		
		$im = imagecreate($width, $height);
		$colors = array();
		for($i = 0; $i < 16; $i++) { // goes up to 13 it seems, let's just keep some space
			$c = 255-($i*256/16);
			$colors[$i] = imagecolorallocate($im, $c, $c, $c);
		}

		// plot pixels
		foreach($data as $by => $subdata) {
			foreach($subdata as $bx => $info) {
				$param = $info['param'];
				$cols = $param['bytes']*8/$param['bits'];
				for($sy = 0; $sy < $param['lines']; $sy++) {
					$y = $by+($sy*$this->spacing['vert_calc'])+$this->margin_top;
					if ($y < 0) continue;
					if ($y >= $height) continue;

					for($sx = 0; $sx < $cols; $sx++) {
						// get this pixel
						$offset = ($param['bytes']*$sy) + (int)floor($sx * $param['bits'] / 8);
						$suboffset = $sx * $param['bits'] % 8;
						$value = ord($info['buf'][$offset]);
						$value = ($value >> (6-$suboffset)) & 0x3;
						$x = $bx+($sx*$this->spacing['horiz_calc']);
						if ($x >= $width) continue;
						$base = imagecolorat($im, $x, $y);
						if ($base) $value = min($value+$base, 255);

						if ($value)
							imagesetpixel($im, $x, $y, $colors[$value]);
					}
				}
			}
		}

		if ($this->debug) echo "Final image: width=$width height=$height (offset=$offset_x,$offset_y)\n";

		return imagepng($im, $out);
	}

	protected function _parse_esc_ext() { // ESC ( xx
		$ext = fgetc($this->fp);

		switch($ext) {
			case 'c': // Set the vertical page margins of the page in "pageunits"
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				switch($mode) {
					case 4: $data = unpack('itop/vlength', fread($this->fp, 4)); break;
					case 8: $data = unpack('Itop/Vlength', fread($this->fp, 8)); break;
					default: echo "UNKNOWN SIZE FOR ESC (c\n"; $data=array('top' => 0, 'length' => 0); break;
				}
				if ($this->debug) echo "SET MARGINTOP=".$data['top']." DOTS, PRINTABLE LENGTH=".$data['length']." DOTS (see BASIC UNIT for value)\n";
				$this->margin_top = $data['top']/2;
				break;
			case 'e': // Choose print dotsize
				$len = $mode = ord(fgetc($this->fp));
				$len |= ord(fgetc($this->fp)) << 8;
				if ($len != 2) {
					if ($this->debug) echo "CHOOSE PRINT DOTSIZE: BAD PACKET\n";
					break;
				}
				$zero = ord(fgetc($this->fp));
				$size = ord(fgetc($this->fp));
				if ($this->debug) echo "CHOOSE PRINT DOTSIZE=$size\n";
				break;
			case 'i': // set MICROWEAVE (On older printers, this is used to turn on microweave; on newer printers, it prints one row at a time.)
				$microweave = ord(fgetc($this->fp));
				if ($this->debug) echo "SET MICROWEAVE=$microweave (1=on 2=full-overlap 3=four-pass 4=full-overlap2)\n";
				break;
			case 'm': // ?
				$len = $mode = ord(fgetc($this->fp));
				$len |= ord(fgetc($this->fp)) << 8;
				$buf = fread($this->fp, $len);
				if ($this->debug) $this->_parse_dump($buf, 'DATA FOR UNKNOWN SEQUENCE ESC ( m');
				break;
			case 'v':
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				switch($mode) {
					case 2: $data = unpack('vlength', fread($this->fp, 2)); break;
					case 4: $data = unpack('Vlength', fread($this->fp, 4)); break;
					default: if ($this->debug) echo "UNKNOWN SIZE FOR ESC (v\n"; $data=array('length' => 0); break;
				}

				if ($this->debug) echo "ADVANCE PRINTING POSITION BY ".$data['length']." POINTS (see BASIC UNIT for value)\n";
				$this->pos_y += ($data['length']/2);
				break;
			case 'C': // Set the length of the page in "pageunits"
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				switch($mode) {
					case 2: $data = unpack('vpagelength', fread($this->fp, 2)); break;
					case 4: $data = unpack('Vpagelength', fread($this->fp, 4)); break;
					default: if ($this->debug) echo "UNKNOWN SIZE FOR ESC (C\n"; $data=array('pagelength' => 0); break;
				}
				if ($this->debug) echo "SET PAGELENGTH=".$data['pagelength']." DOTS (see BASIC UNIT for value)\n";
				break;
			case 'D': // Set printer horizontal and vertical spacing
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				if ($mode != 4) {
					if ($this->debug) echo "PRINTER SET HORIZ. VERT. SPACING: BAD FORMAT\n";
					break;
				}
				$data = unpack('vbase/Cvert/Choriz', fread($this->fp, 4));
				$data['vert_calc'] = $data['vert'] * 720 / $data['base'];
				$data['horiz_calc'] = $data['horiz'] * 720 / $data['base'];
				$this->spacing = $data;
				if ($this->debug) echo "SET SPACING HORIZ=".$data['horiz'].' VERT='.$data['vert'].' AT '.$data['base']." DPI\n";
				break;
			case 'G': // graphic mode
				$data = fread($this->fp, 3);
				if ($data != "\x01\x00\x01") {
					if ($this->debug) echo "ENABLE GRAPHIC MODE (invalid)\n";
				} else {
					if ($this->debug) echo "ENABLE GRAPHIC MODE\n";
				}
				break;
			case 'K': // Set color or grayscale mode
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				if ($mode != 2) {
					if ($this->debug) echo "INVALID SET GREYSCALE (mode)\n";
					break;
				}
				if (ord(fgetc($this->fp)) != 0) {
					if ($this->debug) echo "INVALID SET GREYSCALE (zero)\n";
					break;
				}
				$graymode = ord(fgetc($this->fp));
				if ($this->debug) echo "SET GRAYSCALE=$graymode (0=color 1=grayscale 2=color)\n";
				break;
			case 'R': // REMOTE1, terminated with \x1b\0\0\0
				$buf = '';
				while((substr($buf, -4) != "\x1b\0\0\0") && (!feof($this->fp))) $buf .= fgetc($this->fp);
				if ($this->debug) $this->_parse_dump($buf, 'Remote sequence');
				break;
			case 'S': // Set the width and length of the printed page region in "pageunits" 
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				switch($mode) {
					case 4: $data = unpack('vwidth/vlength', fread($this->fp, 4)); break;
					case 8: $data = unpack('Vwidth/Vlength', fread($this->fp, 8)); break;
					default: if ($this->debug) echo "UNKNOWN SIZE FOR ESC (S\n"; $data=array('width' => 0, 'length' => 0); break;
				}
				if ($this->debug) echo "SET PRINTABLE REGION WIDTH=".$data['width']." DOTS, PRINTABLE LENGTH=".$data['length']." DOTS (see BASIC UNIT for value)\n";
				$this->size_x = $data['width']/2;
				$this->size_y = $data['length']/2;
				break;
			case 'U': // unit
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				switch($mode) {
					case 1:
						$unit = ord(fgetc($this->fp));
						if ($this->debug) echo "SET BASIC UNIT TO ".(3600/$unit)."\n";
						break;
					case 5:
						$data = fread($this->fp, 5);
						$data = unpack('Cpageunit/Cvunit/Chunit/vbaseunit', $data);
						$res = array();
						$res['pageunit'] = $data['baseunit'] / $data['pageunit'];
						$res['vunit'] = $data['baseunit'] / $data['vunit'];
						$res['hunit'] = $data['baseunit'] / $data['hunit'];
						if ($this->debug) echo "SET BASIC UNITS TO ".http_build_query($res, '', '&')." dpi\n";
						break;
					default:
						echo "UNKNOWN TYPE FOR SET BASIC UNIT\n";
						break;
				}
				break;
			case '$': // Set horizontal position to OFFSET from the left margin
				$mode = ord(fgetc($this->fp));
				$mode |= ord(fgetc($this->fp)) << 8;
				if ($mode != 4) {
					if ($this->debug) echo "SET HORIZONTAL POSITION: BAD PACKET SIZE\n";
					break;
				}
				$data = unpack('Vpos', fread($this->fp, 4));
				if ($this->debug) echo "SET HORIZONTAL POSITION=".$data['pos']." POINTS\n";
				$this->pos_x = $data['pos']/2;
				break;
			default:
				$this->_parse_dump("\x1b(".$ext, 'Unknown EXT ESC sequence');
				exit;
		}
	}

	protected function _parse_decompress_packbits($len) {
		// parse and decompress PackBits format until we fill $len bytes
		// infos: http://en.wikipedia.org/wiki/PackBits

		$res = '';
		while(strlen($res) < $len) {
			list(,$c) = unpack('c', fgetc($this->fp));
			if ($c == -128) continue; // "skip to next byte"
			if ($c >= 0) {
				$c+=1;
				$res .= fread($this->fp, $c);
				continue;
			}
			// negative: one byte repeated
			$c = 1-$c;
			$res .= str_repeat(fgetc($this->fp), $c);
		}
		return $res;
	}

	protected function _parse_esc() { // ESC xx
		$esc = fgetc($this->fp);
		switch($esc) {
			case 'i': // Print data in the newer printers (that support variable dot size), and Stylus Pro models
				$data = unpack('Ccolor/Ccompress/Cbits/vbytes/vlines', fread($this->fp, 7));
				$colors = array(0 => 'black', 1 => 'magenta', 2 => 'cyan', 4 => 'yellow', 17 => 'light_magenta', 18 => 'light_cyan');
				$col = $data['color'];
				if (isset($colors[$col])) $col = $colors[$col];
				$buf = $this->_parse_decompress_packbits($data['bytes'] * $data['lines']);
				if ($this->debug) echo "PRINT DATA COLOR=".$col." COMPRESS=".$data['compress']." BITS=".$data['bits']." BYTES=".$data['bytes']." LINES=".$data['lines']." DATA=".strlen($buf)." BYTES\n";
				$this->img_buffer[$col][$this->pos_y][$this->pos_x] = array('param' => $data, 'buf' => $buf);
				break;
			case 'U': // set direction
				$direction = ord(fgetc($this->fp));
				if ($this->debug) echo "SET UNIDIRECTIONAL=".$direction." (0=bidirectional, faster print. 1=unidirectional, slower print)\n";
				break;
			case "\x01": // read until we read "\x1b@"
				$buf = '';
				while((substr($buf, -2) != "\x1b@") && (!feof($this->fp))) $buf .= fgetc($this->fp);
				if ($this->debug) $this->_parse_dump($buf, 'Remote mode command (advanced)');
				break;
			case "\x19": // UNKNOWN, PARAM 1
				$param = ord(fgetc($this->fp));
				if ($this->debug) printf("UNKNOWN SEQ: ESC 19 %02x\n", $param);
				break;
			case "@":
				if ($this->debug) echo "RESET\n";
				break;
			case '(':
				$this->_parse_esc_ext();
				break;
			default:
				$this->_parse_dump("\x1b".$esc, 'Unknown ESC sequence');
				exit;
		}
	}

	protected function _parse_dump($buf, $name) {
		echo $name.":\n";
		if ($buf=='') {
			echo "  (empty)\n";
			return;
		}
		$offset = 0;
		while($buf != '') {
			$data = substr($buf, 0, 16);
			$buf = (string)substr($buf, 16);
			printf("  %04x: ", $offset);
			for($i = 0; $i < 16; $i++) {
				if ($i >= strlen($data)) {
					echo "   ";
				} else {
					printf("%02x ", ord($data[$i]));
				}
			}
			echo " | ";
			for($i = 0; $i < 16; $i++) {
				if ($i >= strlen($data)) break;
				$d = ord($data[$i]);
				if (($d>=0x20) && ($d<=0x7f)) {
					echo $data[$i];
				} else {
					echo '.';
				}
			}
			echo " |\n";
			$offset += 0x10;
		}
	}

	public function parse() {
		rewind($this->fp);

		while(!feof($this->fp)) {
			$cmd = fgetc($this->fp);
			if ($cmd === false) continue;

			switch($cmd) {
				case "\x00": break; // ignore
				case "\x0c": if ($this->debug) echo "FORM FEED\n"; break;
				case "\x0d": if ($this->debug) echo "LINE FEED\n"; break;
				case "\x1b": $this->_parse_esc(); break;
				default:
					echo "RAW: ".bin2hex($cmd)."\n";
			}
		}
	}
}

