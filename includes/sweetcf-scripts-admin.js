/*
 * javascript functions for sweetContact Form admin area
 */

// The following was moved to display_options() so I could set the default tab
//// Set up tabs for options page
//jQuery(function() {
//	jQuery( "#tabs" ).tabs({ active: 1 });
////	$( ".selector" ).tabs({ active: 1 });
////	jQuery( "#tab-list" ).attr('display', 'block');
//});

// Give a warning if the user tries to leave the page w/o saving changes
var swcf_warning = false;
var swcf_submit = false;
window.onbeforeunload = function() {
	// alert('swcf_submit='+swcf_submit+ '   swcf_warning='+swcf_warning);
	if (swcf_warning && !swcf_submit) {
		return sweetcf_transl.unsaved_changes;  // This text will appear on IE and Chrome, but not Firefox
	}
}

// Detect whether the form has been changed
// If the form has changed, set a hidden field to "1"
jQuery(document).ready(function() {
	// for a list of possible selectors in the statement below, see http://www.w3schools.com/jquery/jquery_ref_selectors.asp
	jQuery("#swcf-optionsform").change(function() {
		jQuery("input[name='form-changed']").val('1');
		// Ignore changes on tab 8 (Tools) and tab 9 (Newsletter) since these are beyond the form and not saved in the options table
		var tabId = jQuery("li.ui-state-active").attr("id");
		if ( tabId !== undefined) {
			tabId = tabId.substr(8); //fscf-tab4
		} else {
			tabId = 1;
		}
		if (tabId < 8) {
			swcf_warning = true;
			// Turn on notices to save changes
			jQuery(".swcf-save-notice").css('display', 'block');
		}
//        alert('Something changed');
	});

	// Detect the press of the submit button
	jQuery(".submit").click(function() {
		//alert('Button pressed was ' + document.pressed);
		//var tools_url = document.getElementById("tools-admin-url").value;
		tools_url = jQuery("#tools-admin-url").val();
		var myform = document.getElementById("swcf-optionsform");
		var resp;
		// Find out which button was pressed
		switch (document.pressed) {
			case sweetcf_transl.save_changes:
				swcf_submit = true;		// Don't issue a warning about leaving the page
				// Store the tab ID for use in the validate function
				var tabId = jQuery("li.ui-state-active").attr("id");
				if ( tabId !== undefined) {
					tabId = tabId.substr(8); //fscf-tab4
				} else {
					tabId = 1;
				}
				jQuery("input[name='current_tab']").val(tabId);
				break;
			case sweetcf_transl.send_test:
				// the following line doesn't work in IE because WP adds a hidden field named 'action'
				// document.swcf-optionsform.action = tools_url;
				myform.setAttribute("action", tools_url);
				break;
			case sweetcf_transl.copy_settings:
				resp = confirm(sweetcf_transl.confirm_change);
				if (resp)
					myform.setAttribute("action", tools_url);
				else
					return(false);
				break;
			case sweetcf_transl.backup_settings:
				myform.setAttribute("action", tools_url);
				break;
			case sweetcf_transl.restore_settings:
				resp = confirm(sweetcf_transl.confirm_change);
				if (resp)
					myform.setAttribute("action", tools_url);
				else
					return(false);
				break;
		}
		return(true);

	});

	// Update field order using drag and drop
	jQuery('.swcf_field_settings').sortable({
		items: '.swcf_field',
		opacity: 0.6,
		cursor: 'move',
		axis: 'y',
		//update: function() {
		//var order = $(this).sortable('serialize');
		//$.post(ajaxurl, order, function(response) {
		// alert(response);
		//});
		//	}
	});

//	jQuery( "#tab-list" ).attr('display', 'block');
	jQuery('#fscf-tab-list').css('visibility', 'visible');
	jQuery("a.show-in-popup").click(function(e) {
		popupCenter(jQuery(this).attr('href'), 800, 650, jQuery(this).data().popup_window);
		e.stopPropagation();
		e.preventDefault();
	});
	jQuery(".no-save-changes").click(function(e) {
		swcf_warning = false;
	});
});

function sweetcf_add_new_form($text) {
		// "Add a New Form" was selected
		//if (swcf_warning) {
			//$resp = confirm("You have unsaved changes to your form.  If you proceed, any changes will be lost.\n\nAre you sure you want to continue?");
			// Reset the selection to the current form, in case we stay on the page
			//sel.selectedIndex = +sel.name - 1;	// The name was set to the current form number
		//} else {
			//$resp = true;
		//}
		//if ($resp) {
			// Create a new form: find the form number
			var sel = document.getElementById("form_select");
			var last_index = sel.length - 1; //var last_index = sel.length - 2; // TODO: Patch ?
			
			//console.log("last_index: "+last_index);
			var last_form = sel.options[last_index].value;
			var new_form = +last_form + 1;
			theUrl = sweetcf_get_url(true) + '&swcf_form=' + new_form + '&swcf_tab=1';	// get the URL, strip swcf_form

			// Change the form action, set ctf_action value, and submit the form
			var myForm = document.getElementById("swcf_form_control");
			myForm.action = theUrl;
			var myAction = document.getElementById("ctf_action");
			myAction.setAttribute("value", $text);
			myForm.submit();
		//}
}

function sweetcf_set_form($text) {
	// $text is the translated version of "Add Form"
	// parm was: val
	//Load options page to display selected form
//	alert('  jQuery verion is ' + jQuery.fn.jquery);
	var sel = document.getElementById("form_select");
	var theValue = sel.options[sel.selectedIndex].value;
	var theIndex = sel.options[sel.selectedIndex].index;
//	alert('Form number is ' + theValue);
//	alert('Select index is ' + theIndex);
//	var theIndex = val.formSelect.options[val.formSelect.selectedIndex].value ;

	var $resp, theUrl;
	if ('0' === theValue) {
		// "Add a New Form" was selected
		if (swcf_warning) {
			$resp = confirm("You have unsaved changes to your form.  If you proceed, any changes will be lost.\n\nAre you sure you want to continue?");
			// Reset the selection to the current form, in case we stay on the page
			sel.selectedIndex = +sel.name - 1;	// The name was set to the current form number
		} else {
			$resp = true;
		}
		if ($resp) {
			// Create a new form
			// Find the form number for the new form
			var last_index = sel.length - 2;
			var last_form = sel.options[last_index].value;
//			alert("Last form is " + last_form);
			var new_form = +last_form + 1;
//			alert('Next form number is ' + new_form);
			theUrl = sweetcf_get_url(true) + '&swcf_form=' + new_form + '&swcf_tab=1';	// get the URL, strip swcf_form
//			sweetcf_postwith(theUrl, {ctf_action: $text});

			// Change the form action, set ctf_action value, and submit the form
			var myForm = document.getElementById("swcf_form_control");
			myForm.action = theUrl;
			var myAction = document.getElementById("ctf_action");
			myAction.setAttribute("value", $text);
			myForm.submit();
		}
	} else {
		// Change the form number in the form action url
		theUrl = sweetcf_get_url(true);	// get URL, strip swcf_form parm
		theUrl = theUrl + '&swcf_form=' + theValue;
//		alert("New url is " + theUrl);
		if (theUrl !== "") {
			if (swcf_warning) {
				// The form has been changed, so we might not be leaving the page
				// Reset the selection to the current form, in case we stay on the page
				sel.selectedIndex = +sel.name - 1;	// The name was set to the current form number
			}
			var myForm = document.getElementById("swcf_form_control");
			myForm.action = theUrl;
			// Diaplay "loading" gif
			jQuery("#ctf-loading").css('display', 'block');
			myForm.submit();
			// location.href = theUrl ;
		}
	}
	// This should give the id of the current tab: var id = jQuery("li.tab:eq("+selected+")").attr('id');
	// to select a certain tab: jQuery("#tabs").tabs("select","#tabs-3");
}

function sweetcf_get_url(strip_form) {
	// Gets the current URL, and updates the swcf_tab parm
	// Optionally, remove the swcf_form parm based on the boolean parm strip_form
//	var tabId = jQuery("li.ui-tabs-selected").attr("id");
	var tabId = jQuery("li.ui-state-active").attr("id");
//	alert('Tab ID is ' + tabId);
	if (typeof (tabId) == 'string')
		tabId = tabId.substr(8); //fscf-tab4
	else
		tabId = '1';
	if (!typeof (tabId) == 'number')
		tabId = '1';

	var parts = document.location.href.split("&");
	var theUrl = parts[0];
	if (!strip_form) {
		var i = 1;
		while (i < parts.length) {
			if ("swcf_form=" == parts[i].substr(0, 10))
				theUrl = theUrl + "&" + parts[i];
			i++;
		}
	}
//	var i = 1;
//	while ( i < parts.length ) {
//		if ( ! ( ( no_form && ( "swcf_form=" == parts[i].substr(0,10))) || ( "swcf_tab=" == parts[i].substr(0,9) ) ) )
//			theUrl = theUrl + "&" + parts[i];
//		i++;
//		}
	theUrl = theUrl + '&swcf_tab=' + tabId;

	return theUrl;
}

function sweetcf_postwith(toUrl, parms) {
	var myForm = document.createElement("form");
	myForm.method = "post";
	myForm.action = toUrl;
	for (var k in parms) {
		var myInput = document.createElement("input");
		myInput.setAttribute("name", k);
		myInput.setAttribute("value", parms[k]);
		myForm.appendChild(myInput);
	}
	document.body.appendChild(myForm);
	myForm.submit();
	document.body.removeChild(myForm);
}

function toggleVisibility(id) {
	var e = document.getElementById(id);
	if (e.style.display == 'block')
		e.style.display = 'none';
	else
		e.style.display = 'block';
}

// show hide toggle button for fields settings
function toggleVisibilitybutton(id) {
	var thisid = id;
	var oDiv = document.getElementById('field' + thisid)
	var oBtn = document.getElementById('button' + thisid)
	if (oDiv.style.display == 'block') {
		oDiv.style.display = 'none';
		oBtn.value = 'Show Details';
	} else {
		oDiv.style.display = 'block';
		oBtn.value = 'Hide Details';
	}
}

function sweetcf_add_field($text) {
	//alert ('Under development !'); return;
	var n = document.getElementById('fs_options');
	nonce = n.value;
	if (swcf_warning) {
		$resp = confirm("You have unsaved changes to your form.  If you proceed, any changes will be lost.\n\nAre you sure you want to continue?");
	} else {
		$resp = true;
	}
	if ($resp) {
//		swcf_warning = true;	// This doesn't seem to have any effect
		var theUrl = sweetcf_get_url(false);	// get the URL, don't strip swcf_form
		sweetcf_postwith(theUrl, {ctf_action: $text, fs_options: nonce});
	}
}

function sweetcf_delete_field(key) {
	// Mark the field for deletion.  It will be deleted in validate()
	var e = document.getElementById('swcf_contact_field' + key + '_label');
	var resp = confirm("This will permanently delete the field '" + e.value + "'.\nAre you sure?\nThe field will be deleted when you Save Changes.");
	if (resp) {
		//	alert('The field will be deleted when you Save Changes.');
		swcf_warning = true;
		// Mark field for deletion
		e = document.getElementById('delete-' + key);
		e.value = "true";
		// Hide the field on the display
		e = document.getElementById('field-' + key);
		e.style.display = 'none';
	}
}


function sweetcf_reset_form() {
	// sweetcf_transl.reset_form is the translated version of "Reset Form"
	var n = document.getElementById('fs_options');
	nonce = n.value;
	$resp = confirm("This will set this form back to the default settings.  All tabs will be affected.  This cannot be reversed.\n\nAre you sure?");
	if ($resp) {
//		alert("This will eventually reset the form.");  // XXX write function for reset form
		var theUrl = sweetcf_get_url(false);	// get the URL, don't strip swcf_form
		sweetcf_postwith(theUrl, {ctf_action: sweetcf_transl.reset_form, fs_options: nonce});
	}
}

function sweetcf_reset_all_styles() {
	// sweetcf_transl.reset_all_styles is the translated version of "Reset Styles on all forms"
	var n = document.getElementById('fs_options');
	nonce = n.value;
	$resp = confirm("This will reset default style settings on all forms. This cannot be reversed.\n\nAre you sure?");
	if ($resp) {
//		alert("This will reset all styles.");
		var theUrl = sweetcf_get_url(false);	// get the URL, don't strip swcf_form
		sweetcf_postwith(theUrl, {ctf_action: sweetcf_transl.reset_all_styles, fs_options: nonce});
	}
}

function sweetcf_delete_form(num) {
	// the form num and name re used in the messages
//	alert('Current form is ' + num);
	var e = document.getElementById('sweetcontact_form_name');
	name = e.value;
	var n = document.getElementById('fs_options');
	nonce = n.value;
//	alert('Form name is ' + name);
	// name id='sweetcontact_form_name'
	var resp = confirm("Form " + num + ": " + name + ".\nThis will permanently delete this form.  This cannot be reversed.\n\nAre you sure?");
	if (resp) {
		var theUrl = sweetcf_get_url(true);	// get the URL, strip swcf_form (so we default to Form 1
		sweetcf_postwith(theUrl, {ctf_action: sweetcf_transl.delete_form, form_num: num, form_name: name, fs_options: nonce});
	}
}

function popupCenter(url, width, height, name) {
	var left = (screen.width / 2) - (width / 2);
	var top = (screen.height / 2) - (height / 2);
	return window.open(url, name, "location=0,resizable=1,scrollbars=1,width=" + width + ",height=" + height + ",left=" + left + ",top=" + top);
}
