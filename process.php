<?php
$files = scandir(__DIR__);
foreach($files as $file) {
	if(pathinfo($file,  PATHINFO_EXTENSION) === 'source') {
		$content = file_get_contents($file);
		if($content) {
			$newContent = [];
			$cleanContent = [];
			$rows = explode("\n", $content);
			foreach($rows as $row) {
				if(!empty($row)) {
					if(strpos($row, '#') === false) {
						$newContent[trim('www.'.$row)] = trim('www.'.$row);
						$newContent[trim($row)] = trim($row);
					} else {
						$newContent[] = trim($row);
					}
					$cleanContent[trim(str_replace('www.', '', $row))] = trim(str_replace('www.', '', $row));
				}
			}
			if(!empty($newContent)) {
				file_put_contents(str_replace('.source', '.txt', $file), implode("\n", $newContent));
				echo 'Written '.str_replace('.source', '.txt', $file).PHP_EOL;
			}
			if(!empty($cleanContent)) {
				file_put_contents($file, implode("\n", $cleanContent));
				echo 'Written '.$file.PHP_EOL;
			}
		}
	}
}