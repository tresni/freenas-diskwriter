<?php
include_once 'config.inc';

abstract class Burner {
	const CLASS_BURN_CD =			1;
	const CLASS_BURN_DVD =			2;
	const CLASS_TRANSPORT_ATAPI =	4;
	const CLASS_TRANSPORT_SCSI =	8;

	protected $_name = 'burner';
	protected $_available = false;
	protected $_drives = array();
	
	function __construct() {
		$this->_available = self::check_for($this->_name);
	}
	
	function get_name() { return $this->_name; }
	function is_available() { return $this->_available; }

	/* abstracts */
	abstract function get_class();
	abstract function get_options();
	abstract function get_drives();

	abstract function burn($img, $dev, $options=array());
	abstract function install();

	/* Static functions */
	static private function check_for($cmd) {
		// we could use tcsh's builtin which, but this is easier for now
		system($cmd, $ret);
		return $ret != 127;
	}
	
	static protected function filter_drives_by_type($type) {
		$drives = array();
		foreach (get_cdrom_list() as $dev=>$spec) {
			if ($spec['type'] == $type)
				$drives[$dev] = $spec['desc'];
		}
		return $drives;
	}
}


class Growisofs extends Burner {
	function __construct() {
		$this->_name = 'growisofs';
		$this->_drives = Burner::filter_drives_by_type('SCSI');
		parent::__construct();
	}

	function get_class() { return Burner::CLASS_BURN_DVD | Burner::CLASS_TRANSPORT_SCSI; }
	function get_drives() { return $this->_drives; }

	function get_options() {
		return array(
			array('Dry Run', 'bool', '-Z')
		);
	}


	function burn($img, $dev, $option=array()) {
		/* growisofs -Z {DEV}={IMG} */
	}
	
	function install() {
		if ($g['platform'] == 'livecd') {
			//should be installed on /mnt partition
			exec('pkg_add -r dvd+rw-tools');
		} else {
			exec('pkg_add -r dvd+rw-tools');
		}
	}
}

class Cdrecord extends Burner {
	function __construct() {
		$this->_name = 'cdrecord';
		$this->_drives = self::scanbus();
		parent::__construct();
	}
	
	function get_class() { return Burner::CLASS_BURN_CD | Burner::CLASS_TRANSPORT_SCSI; }
	function get_drives() { return $this->_drives; }

	function get_options() {
		return array(
			array('Dry Run', 'bool', '--dummy')
		);
	}
	
	function burn($img, $dev, $option=array()) {
		/* cdrecord --dummy dev={DEV} {IMG} */
	}
	
	function install() {
		if ($g['platform'] == 'livecd') {
			// we should install to a path on mount and setup symlinks or something..
			exec('pkg_add -r cdrecord');
		} else {
			exec('pkg_add -r cdrecord');
		}
	}

	static private function scanbus() {
		exec('cdrecord -scanbus', $output);
		/*
			0,0,0	  0) 'Sony    ' 'Storage Media   ' '0100' Removable Disk
			1,1,0	101) '_NEC    ' 'DVD_RW ND-3520AW' '3.07' Removable CD-ROM
			1,2,0	102) *
		*/
		
		$drives = array();				
		foreach ($output as $line) {
			/* $data = */
			preg_match_all("/(\'.*?\'|\S+)/", $line, $data);
			$data = $data[1];
			
			if (count($data) != 7) continue;
			else if (strpos($data[6], 'CD-ROM') === FALSE) continue;
			foreach ($data as &$var) $var = trim($var, " \t\n\r\0\x0B'");

			$drives[$data[0]] = "{$data[2]} {$data[3]} {$data[4]}";
		}
		return $drives;
	}
}

class Burncd extends Burner {
	function __construct() {
		$this->_name = 'burncd';
		$this->_drives = Burner::filter_drives_by_type('IDE');
		parent::__construct();
	}

	function get_class() { return Burner::CLASS_BURN_CD | Burner::CLASS_BURN_DVD | Burner::CLASS_TRANSPORT_ATAPI; }
	function get_drives() { return $this->_drives; }

	function get_options() {
		return array(
			array('Dry Run', 'bool', '-t')
		);
	}
	
	function burn($img, $dev, $option=array()) {
		/* burncd -f {DEV} data {IMG} */
	}
	
	function install() {
		// Holy mother of mary is this not my idea of fun..
		/*
		// where to put the image...
		$ARCH = $g['arch'];
		exec('fetch ftp://ftp.freebsd.org/pub/FreeBSD/ISO-IMAGES-$ARCH/7.2/7.2-RELEASE-$ARCH-livefs.iso');
		$MD_DEV = exec('mdconfig -a -t vnode -f $ISOFILE');
		exec('mount_cd9660 /dev/$MD_DEV /mnt/????');
		*/
	}
}

class BurnerFactory {
	const CD_700_SIZE = 720000; // 703.125 * 1024;
	const CD_900_SIZE = 890999; // 870.117 * 1024;
	private $_burners;
	
	function __construct() {
		$this->_burners  = array(new Growisofs, new Burncd, new Cdrecord);
	}

	function list_burners($all_drives = false) {
		$burners = array();
		foreach ($this->_burners as $burner) {
			if ($all_drives || $burner->is_available()) {
				$burners[] = $burner->get_name();
			}
		}
		return $burners;
	}
	
	function find_burner_by_name($name) {
		foreach ($this->_burners as $burner) {
			if ($burner->get_name() == $name) {
				return $burner;
			}
		}
		return false;
	}
	
	private function find_burners_by_class($class) {
		$burners = array();
		foreach ($this->_burners as $burner) {
			if (($burner->get_class() & $class) && $burner->is_available()) {
				$burners[] = $burner->get_name();
			}
		}
		return $burners;
	}

	function find_burners_for_image($img) {
		if (!file_exists($img)) return array();
		
		$res = exec('du -k ' . $img);
		list($size, $name) = preg_split('/\s+/', $res, 2);
		
		if ($size <= BurnerFactory::CD_700_SIZE)
			return $this->find_burners_by_class(Burner::CLASS_BURN_CD);
		else if ($size <= BurnerFactory::CD_900_SIZE)
			return $this->list_burners();
		else
			return $this->find_burners_by_class(Burner::CLASS_BURN_DVD);
	}	
}

$factory = new BurnerFactory();
