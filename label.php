<?php

require_once('EpsonBuildPrn.php');

$imagick = new imagick('label_one.png');
$draw = new ImagickDraw();
$color = new ImagickPixel('black');
$background = new ImagickPixel('none');

$text = 'Test with 日本語';

$draw->setFont('/usr/share/fonts/corefonts/arialuni.ttf');
$draw->setFontSize(100);
$draw->setFillColor($color);
$draw->setStrokeAntialias(true);
$draw->setTextAntialias(true);

$metrics = $imagick->queryFontMetrics($draw, $text);

$draw->annotation((3422-$metrics['textWidth'])/2, 900, $text);

$imagick->drawImage($draw);

file_put_contents('label_test.png', $imagick);

$img = imagecreatefromstring($imagick);
//$img = imagecreatefrompng('label_one.png');

$img2 = imagecreate(3514,3514); // our target image
$colors = array();
for($i = 0; $i < 16; $i++) { // goes up to 13 it seems, let's just keep some space
	$c = 255-($i*256/16);
	$colors[$i] = imagecolorallocate($img2, $c, $c, $c);
}

// recompute colors on range 0~4

for($x = 0; $x < imagesx($img); $x++) {
	for($y = 0; $y < imagesy($img); $y++) {
		$col = imagecolorsforindex($img, imagecolorat($img, $x, $y));
		$alpha = ($col['alpha']);
		$color = $col['red'];
		if ($alpha) $color = 0xff;
		$final = 4-(int)round($color/0xff*4);
		imagesetpixel($img2, $x, $y, $final);
	}
	printf("%01.2f%%\r", $x/3514*100);
}
echo "finished!\n";

imagepng($img2, 'label_fix.png');

//$img2 = imagecreatefrompng('label_fix.png');

$p = new PrnBuilder(array('black' => $img2));
$p->gen('disk.prn');

