#!/usr/local/bin/php -f
<?php
require_once 'util.inc';
require_once 'guiconfig.inc';

require_once 'ext/DiskWriter/Burner.class.php';

class HTMLClassedCheckBox extends HTMLCheckBox {
	var $_subclass="";
	
	function __construct($ctrlname, $class, $title, $value, $caption, $description = "") {
		parent::__construct($ctrlname, $title, $value, $caption, $description);
		$this->SetSubclass($class);
	}
	
	function SetSubclass($class) {
		$this->_subclass = $class;
	}
	
	function GetSubclass() {
		return $this->_subclass;
	}
	
	function RenderCtrl() {
		$ctrlname = $this->GetCtrlName();
        $caption = $this->GetCaption();
        $description = $this->GetDescription();
        $param = $this->GetParam();
		$class = $this->GetSubclass();

        echo "      <input name='{$ctrlname}' type='checkbox' class='formfld {$class}' id='{$ctrlname}' value='yes' {$param} />&nbsp;{$caption}\n";
	}
}

$pgtitle = array(gettext('Extensions'), gettext('DiskWriter'));

if (isset($_POST['ajax'])) {
	header('Content-Type: application/json');
	if (isset($_POST['image'])) {
		echo json_encode($factory->find_burners_for_image($_POST['image']));
		exit;
	} else if (isset($_POST['burner'])) {
		$burner = $factory->find_burner_by_name($_POST['burner']);
		if ($burner === false) {
			echo json_encode(array());
		} else {
			echo json_encode($burner->get_drives());
		}
		exit;
	}
	echo json_encode($_POST);
	exit;
}

require_once 'auth.inc';
include 'fbegin.inc';
?>
<script src="/ext/DiskWriter/js/jquery.js"></script>
<script src="/ext/DiskWriter/js/jquery.form.js"></script>
<script>
$(function() {
	$('#burner_tr, #drive_tr, #write_tr').hide();
	$('#imagebrowsebtn').click(function() {
		$('#burner_tr, #drive_tr').hide();
		$('#burner').empty();
		timer = setInterval('polling()', 100);
	});
	$('#burner').change(function() {
		$('#drive_tr').hide();
		$('#drive').empty();
		$.post('extension_diskwriter.php', {
				ajax: 1,
				burner: $('#burner').val(),
				authtoken: $('input[name=authtoken]').val()
			},
			function(data) {
				$('#drive').empty();
				$.each(data, function(val, text) {
					$('#drive').append(
						$('<option></option>').val(val).html(text)
					);
				});
				$('#drive_tr').show();
				$('#drive').change();
			}
		);
	});
	$('ul#tabnav a').click(function() {
		$('ul#tabnav li').removeClass('tabact').addClass('tabinact');
		$(this).parent().removeClass('tabinact').addClass('tabact');
		$('.diskwriter').hide();
		$('.' + $(this).attr('id')).show();
		return false;
	});
	$('.burnsoft:checked, #image').attr('disabled', 'disabled');
	$('.burnsoft').clicked(function()  {
		// install, recheck, disable control
	})
/* can't use ajax as we need to target an iframe for to get all the output */
/* iframe uses window.parent.document to modify stuff in here              */
/* ex. $('#somediv', window.parent.document).html("hello World");          */
/* iframe is php using popen/feof/fgets to get output/status               */
/* need to write classes for burners to encapsulate options, process       */
/*  output format, etc                                                     */
/*burncd:
writing from file /mnt/NewData/Games/RiskII/RISKII.iso size 346322 KB
Written this track 132672 KB (38%) total 132672 KB

*/
});

function polling() {
	if (filechooser && filechooser.closed) {
		clearInterval(timer);
		$.post('extension_diskwriter.php', {
				ajax: 1,
				image: $('#image').val(),
				authtoken: $('input[name=authtoken]').val()
			},
			function(data) {
				$('#burner').empty();
				$.each(data, function(idx, text) {
					$('#burner').append(
						$('<option></option>').html(text)
					);
				});
				$('#burner_tr').show();
				$('#burner').focus().change();
			}
		);
	}
}
</script>
<table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr class="navigation">
		<td class="tabnavtbl">
			<ul id="tabnav">
				<li class="tabact"><a title="Burn Disc" id='dw_burn'><span>Burn Disk</span></a></li>
				<li class="tabinact"><a title="Setup" id='dw_setup'><span>Setup</span></a></li>
			</ul>
		</td>
	</tr>
	<tr class="dw_burn diskwriter">
    	<td class="tabcont">
            <form id="command_form" method="post">
                <table width="100%" border="0" cellpadding="0" cellspacing="0">
                    <?php html_filechooser("image", gettext("Image to write"), null, null, $g['media_path'], true); ?>
                    <?php html_combobox("burner", gettext("Burning software"), null, array(), null, true); ?>
					<?php html_combobox("drive", gettext("Drive to use"), null, array(), null, true); ?>
					<?php /* html_checkbox("eject", null, true, gettext("Eject disc from drive when write has completed")); */ ?>
					<tr id='write_tr'>
						<td colspan="2">
							<input type="submit" value="Write Disc" />
							<input type="hidden" name="ajax" value="1" />
						</td>
					</tr>
                </table>
                <?php include("formend.inc");?>
            </form>
        </td>
    </tr>
	<tr class="dw_setup diskwriter" style='display:none'>
		<td class="tabcont">
			<table width="100%" border="0" cellpadding="0" cellspacing="0">
				<?php
					foreach ($factory->list_burners(true) as $burner) {
						$avail = $factory->find_burner_by_name($burner)->is_available();
						$ctrl = new HTMLClassedCheckBox($burner, "burnsoft", $burner, $avail, (!$avail
							? "Check the box to install $burner"
							// Since sh doesn't have a builtin which, we'll use tcsh's
							: exec("tcsh -c \"which $burner\""))
						);
						$ctrl->Render();
					}
				?>
			</table>
		</td>
	</tr>
</table>
<?php
include('fend.inc');
