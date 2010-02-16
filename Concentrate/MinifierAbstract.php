<?php

require_once 'Concentrate/Exception.php';

abstract class Concentrate_MinifierAbstract
{
	abstract public function minify($content, $type);

	public function minifyFile($fromFilename, $toFilename, $type)
	{
		if (!is_readable($fromFilename)) {
			throw new Concentrate_FileException(
				"Could not read {$fromFilename} for minification.",
				0,
				$fromFilename
			);
		}

		$toDirectory = dirname($toFilename);
		if (!file_exists($toDirectory)) {
			mkdir($toDirectory, 0770, true);
		}
		if (!is_dir($toDirectory)) {
			throw new Concentrate_FileException(
				"Could not write to directory {$toDirectory} because it " .
				"is not a directory.",
				0,
				$toDirectory
			);
		}

		$content = file_get_contents($fromFilename);
		$content = $this->minify($content, $type);
		file_put_contents($toFilename, $content);
	}
}

?>
