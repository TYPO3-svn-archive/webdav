<?php

class Tx_Webdav_ViewHelpers_BreadcrumbViewHelper extends Tx_Fluid_Core_ViewHelper_AbstractViewHelper {
	/**
	 * @param string $path
	 * @param string $base
	 * @param string $separator
	 *
	 * @return string
	 */
	public function render($path, $base, $separator = ' / ') {
		$name = basename($path);
		$rest = dirname($path);
		$return = $separator . '<a href="' . $base . $path . '">' . $name . '</a>';
		if($rest !== '.') {
			$return = $this->render($rest, $base) . $return;
		} else {
			$return = '<a href="' . $base . '">Rootfolder</a>' . $return;
		}
		return $return;
	}
}