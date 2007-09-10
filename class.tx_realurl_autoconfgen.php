<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2004 Martin Poelstra (martin@beryllium.net)
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * Class for translating page ids to/from path strings (Speaking URLs)
 *
 * $Id: class.tx_realurl_advanced.php 5893 2007-07-09 13:41:07Z liels_bugs $
 *
 * @author	Martin Poelstra <martin@beryllium.net>
 * @coauthor	Kasper Skaarhoj <kasper@typo3.com>
 */
/**
 * [CLASS/FUNCTION INDEX of SCRIPT]
 */

/**
 * Class for generating of automatic RealURL configuration
 *
 * @author	Dmitry Dulepov <dmitry@typo3.org>
 * @package realurl
 * @subpackage tx_realurl
 */
class tx_realurl_autoconfgen {

	var $db;

	/**
	 * Generates configuration. Locks configuration file for exclusive access to avoid collisions. Will not be stabe on Windows.
	 */
	function generateConfiguration() {
		$fileName = PATH_site . TX_REALURL_AUTOCONF_FILE;
		$fd = @fopen($fileName, 'a+');
		if ($fd) {
			@flock($fd, LOCK_EX);
			// Check size
			fseek($fd, 0, SEEK_END);
			if (ftell($fd) == 0) {
				$this->doGenerateConfiguration($fd);
			}
			@flock($fd, LOCK_UN);
			fclose($fd);
		}
	}

	/**
	 * Performs actual generation.
	 *
	 * @param	resource	$fd	FIle descriptor to write to
	 */
	function doGenerateConfiguration(&$fd) {

		if (!isset($GLOBALS['TYPO3_DB'])) {
			if (!TYPO3_db)	{
				return;
			}
			$this->db = t3lib_div::makeInstance('t3lib_db');
			if (!$this->db->sql_pconnect(TYPO3_db_host, TYPO3_db_username, TYPO3_db_password) ||
				!$this->db->sql_select_db(TYPO3_db)) {
					// Cannot connect to database
					return;
			}
		}
		else {
			$this->db = &$GLOBALS['TYPO3_DB'];
		}

		$template = $this->getTemplate();

		// Find all domains
		$domains = $this->db->exec_SELECTgetRows('pid,domainName,redirectTo', 'sys_domain', 'hidden=0',
				'', '', '', 'domainName');
		if (count($domains) == 0) {
			$conf['_DEFAULT'] = $template;
		}
		else {
			foreach ($domains as $domain) {
				if ($domain['redirectTo'] != '') {
					// Redirects to another domain, see if we can make a shortcut
					$parts = parse_url($domain['redirectTo']);
					if (isset($domains[$parts['host']]) && ($domains['path'] == '/' || $domains['path'] == '')) {
						// Make a shortcut
						$conf[$domain['domainName']] = $parts['host'];
						continue;
					}
				}
				// Make entry
				$conf[$domain['domainName']] = $template;
				$conf[$domain['domainName']]['pagePath']['rootpage_id'] = $domain['pid'];
			}
		}

		$_realurl_conf = @unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['realurl']);
		if ($_realurl_conf['autoConfFormat'] == 0) {
			fwrite($fd, '<' . '?php' . chr(10) . '$GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'realurl\']=' .
				'unserialize(\'' . addslashes(serialize($conf)) . '\');' . chr(10) . '?' . '>'
			);
		}
		else {
			fwrite($fd, '<' . '?php' . chr(10) . '$GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTCONF\'][\'realurl\']=' .
				var_export($conf, true) . ';' . chr(10) . '?' . '>'
			);
		}
	}

	/**
	 * Creates common configuration template.
	 *
	 * @return array	Template
	 */
	function getTemplate() {
		$confTemplate = array(
		    'init' => array(
				'enableCHashCache' => true,
				'appendMissingSlash' => 'ifNotFile',
				'adminJumpToBackend' => true,
				'enableUrlDecodeCache' => true,
				'enableUrlEncodeCache' => true,
				'emptyUrlReturnValue' => '/',
		    ),
		    'pagePath' => array(
		        'type' => 'user',
		        'userFunc' => 'EXT:realurl/class.tx_realurl_advanced.php:&tx_realurl_advanced->main',
		        'spaceCharacter' => '-',
				'languageGetVar' => 'L',
//				'expireDays' => 3,
				'firstHitPathCache' => true,
		    ),
			'defaultToHTMLsuffixOnPrev' => 0,
			'acceptHTMLsuffix' => 1,
		);

		// Add print feature if TemplaVoila is not loaded
		if (!t3lib_extMgm::isLoaded('templavoila')) {
			$confTemplate['fileName'] = array(
				'index' => array(
		            'print' => array(
		                'keyValues' => array(
		                    'type' => 98,
		                )
		            ),
				),
			);
		}

		$this->addLanguages($confTemplate);

		// Add popular extensions
		$this->addMininews($confTemplate);

		return $confTemplate;
	}

	function addLanguages(&$conf) {
		$languages = $this->db->exec_SELECTgetRows('t1.uid AS uid,t2.lg_iso2 AS lg_iso2', 'sys_language t1, static_languages t2', 't2.uid=t1.static_lang_isocode AND t1.hidden=0');
		if (count($languages) > 0) {
			$conf['preVars'] = array(
				array(
					'GETvar' => 'L',
					'valueMap' => array(
					),
				    'noMatch' => 'bypass'
				),
			);
			foreach ($languages as $lang) {
				$conf['preVars']['valueMap'][strtolower($lang['lg_iso2'])] = $lang['uid'];
			}
		}
	}

	/**
	 * Adds mininews part of found in file system
	 *
	 * @param	array	$conf	Configuration to add to
	 */
	function addMininews(&$conf) {
		if (@file_exists(PATH_typo3conf . 'ext/mininews/ext_emconf.php')) {
			$conf['postVarSets'][] = array(
				'mininews' => array(
					array(
					    'GETvar' => 'tx_mininews_pi1[showUid]'
					)
				)
			);
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realurl/class.tx_realurl_autoconfgen.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realurl/class.tx_realurl_autoconfgen.php']);
}

?>