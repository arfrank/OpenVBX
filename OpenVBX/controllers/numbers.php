<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * "The contents of this file are subject to the Mozilla Public License
 *  Version 1.1 (the "License"); you may not use this file except in
 *  compliance with the License. You may obtain a copy of the License at
 *  http://www.mozilla.org/MPL/
 
 *  Software distributed under the License is distributed on an "AS IS"
 *  basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See the
 *  License for the specific language governing rights and limitations
 *  under the License.

 *  The Original Code is OpenVBX, released June 15, 2010.

 *  The Initial Developer of the Original Code is Twilio Inc.
 *  Portions created by Twilio Inc. are Copyright (C) 2010.
 *  All Rights Reserved.

 * Contributor(s):
 **/

class NumbersException extends Exception {}

class Numbers extends User_Controller
{
	private $error_message = FALSE;
	private $new_number = null;

	function __construct()
	{
		parent::__construct();
		$this->section = 'numbers';
		$this->admin_only($this->section);
		$this->template->write('title', 'Numbers');
		$this->load->model('vbx_incoming_numbers');
	}

	function index()
	{
		$this->template->add_js('assets/j/numbers.js');

		$data = $this->init_view_data();
		$numbers = array();
		try
		{
			$numbers = $this->vbx_incoming_numbers->get_numbers();
		}
		catch (VBX_IncomingNumberException $e)
		{
			$this->error_message = ErrorMessages::message('twilio_api', $e->getCode());
		}

		$incoming_numbers = array();
		// now generate table
		if(count($numbers) > 0)
		{
			$flows = VBX_Flow::search();
			foreach($numbers as $item)
			{
				$item_msg = '';
				if(is_object($this->new_number) &&
				   $this->new_number->id == $item->id)
				{
					$item_msg = 'New';
				}

				$flow_name = '(Not Set)';
				foreach($flows as $flow)
				{
					if($flow->id == $item->flow_id)
					{
						$flow_name = '';
					}
				}
				
				$incoming_numbers[] = array(
											'id' => $item->id,
											'name' => $item->name,
											'trial' => (isset($item->trial) && $item->trial == 1)? 1 : 0,
											'phone' => format_phone($item->phone),
											'pin' => $item->pin,
											'status' => $item_msg,
											'flow_id' => $item->flow_id,
											'flow_name' => $flow_name,
											'flows' => $flows,
											);
			}

		}
		$data['highlighted_numbers'] = array($this->session->flashdata('new-number'));
		$data['items'] = $incoming_numbers;
		$data['twilio_sid'] = $this->twilio_sid;
		
		if(empty($this->error_message))
		{
			$error_message = $this->session->flashdata('error');
			if(!empty($error_message))
			{
				$this->error_message = $this->session->flashdata('error');
			}
		}

		if(!empty($this->error_message))
		{
			$data['error'] = CI_Template::literal($this->error_message);
		}

		$data['counts'] = $this->message_counts();
		
		$this->respond('', 'numbers', $data);
	}

	function add()
	{
		$json = array( 'error' => false, 'message' => 'Added Number' );

		try
		{
			$is_local = ($this->input->post('type') == 'local');
			$area_code = $this->input->post('area_code');
			$this->new_number = $this->vbx_incoming_numbers->add_number($is_local, $area_code);
			
			$json['number'] = $this->new_number;
			
			$this->session->set_flashdata('new-number', $this->new_number->id);
		}
		catch (VBX_IncomingNumberException $e)
		{
			$code = $e->getCode();
			$json['message'] = $e->getMessage();
			if($code)
			{
				$json['message'] = ErrorMessages::message('twilio_api', $code);
			}
			
			$json['error'] = true;
		}
		
		$data = compact('json');
		$this->respond('', 'numbers', $data);
	}

	function delete($phone_id)
	{
		$confirmed = $this->input->post('confirmed');
		$data['confirmed'] = $confirmed;
		$data['error'] = false;
		$data['message'] = '';
		try
		{
			if(!$confirmed)
			{
				throw new NumberException('Incoming number is not confirmed');
			}
		
			if(empty($phone_id))
			{
				throw new NumberException('Malformed Phone identifier.');
			}

			try
			{
				$this->vbx_incoming_numbers->delete_number($phone_id);
			}
			catch(VBX_IncomingNumberException $e)
			{
				throw new NumberException($e->getMessage());
			}
		}
		catch(NumberException $e)
		{
			$data['error'] = true;
			$data['message'] = $e->getMessage();
		}

		echo json_encode($data);
	}

	function change($phone_id, $id)
	{
		try
		{
			$success = $this->vbx_incoming_numbers->assign_flow($phone_id, $id);
			$message = '';
		}
		catch (Exception $ex)
		{
			$success = FALSE;
			$message = $ex->getMessage();
		}

		echo json_encode(compact('message', 'success', 'id'));
	}

	function token()
	{
		if($this->response_type == 'html')
		{
			redirect('numbers');
			exit;
		}
		
		return $this->token_handler();
	}

	function outgoingcallerid()
	{
		if($this->response_type == 'html')
		{
			redirect('numbers');
			exit;
		}
		
		return $this->outgoingcallerid_handler();
	}

	private function token_handler()
	{
		$data = array();
		switch($this->request_method)
		{
			case 'GET':
				$token = $this->make_rest_access();
				$data['json'] = compact('token');
				break;
			case 'POST':
			case 'DELETE':
			default:
				break;
				
		}
		
		
		$this->respond('', 'numbers', $data);
	}

	private function outgoingcallerid_handler()
	{
		$data = array();
		switch($this->request_method)
		{
			case 'GET':
				try
				{
					$data['json'] = $this->get_outgoingcallerid();
				}
				catch (NumbersException $e)
				{
					$data['json'] = array('error' => true,
										  'message' => $e->getMessage());
				}
				break;
			case 'POST':
			case 'DELETE':
			default:
				break;
		}

		$this->respond('', 'numbers', $data);
	}

	private function get_outgoingcallerid()
	{
		$numbers = array();
		try
		{
			$numbers = $this->vbx_incoming_numbers->get_numbers();
		}
		catch (VBX_IncomingNumberException $e)
		{
			$this->error_message = ErrorMessages::message('twilio_api', $e->getCode());
			throw new NumbersException($this->error_message, $e->getCode());
		}
		
		return $numbers;
	}
	
	private function make_token()
	{
		return $this->make_rest_access();
	}

}