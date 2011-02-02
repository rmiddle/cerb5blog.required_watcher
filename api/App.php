<?php
class Cerb5blogRequiredWatchersEventListener extends DevblocksEventListenerExtension {
	function __construct($manifest) {
		parent::__construct($manifest);
	}

	/**
	 * @param Model_DevblocksEvent $event
	 */
	function handleEvent(Model_DevblocksEvent $event) {
		switch($event->id) {
			case 'context_link.assigned':
				$this->_workerAssigned($event);
				break;
			case 'ticket.reply.inbound':
				//$this->_sendForwards($event, true);
				break;
			case 'ticket.reply.outbound':
				//$this->_sendForwards($event, false);
				break;
		}
	}

	private function _workerAssigned($event) {
		$context = $event->params['context'];
 		
        switch($context) {
			case CerberusContexts::CONTEXT_TICKET:
				$this->_workerAssignedTicket($event);
				break;
            case CerberusContexts::CONTEXT_TASK:
				$this->_workerAssignedTask($event);
				break;
    	}	
    }
    
	private function _workerAssignedTicket($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

		$worker_id = $event->params['worker_id'];
		$context = $event->params['context'];
		$ticket_id = $event->params['context_id'];
		
        $mail_service = DevblocksPlatform::getMailService();
        $mailer = null; // lazy load
        
        $settings = DevblocksPlatform::getPluginSettingsService();
        $default_from = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM, CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
        $default_personal = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_PERSONAL, CerberusSettingsDefaults::DEFAULT_REPLY_PERSONAL);

        $ticket = DAO_Ticket::get($ticket_id);
        
		// Sanitize and combine all the destination addresses
		$next_worker = DAO_Worker::get($worker_id);
        $notify_emails = $next_worker->email;
			
		if(empty($notify_emails))
			return;
			
        $messages = DAO_Message::getMessagesByTicket($ticket_id);			
		$message = end($messages); // last message
		$message_headers = $message->getHeaders();
		unset($messages);
			
		$reply_to = $default_from;
		$reply_personal = $default_personal;
				
		// See if we need a group-specific reply-to
		if(!empty($ticket->team_id)) {
			@$group_from = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_FROM);
			if(!empty($group_from))
				$reply_to = $group_from;
					
			@$group_personal = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_PERSONAL);
			if(!empty($group_personal))
				$reply_personal = $group_personal;
		}
			
		try {
			if(null == $mailer)
				$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
					
		 	// Create the message

			$mail = $mail_service->createMessage();
			$mail->setTo(array($notify_emails));
			$mail->setFrom(array($reply_to => $reply_personal));
			$mail->setReplyTo($reply_to);
			$mail->setSubject(sprintf("[Ticket Assignment #%s]: %s",
				$ticket->mask,
				$ticket->subject
			));
				
			$hdrs = $mail->getHeaders();
				
			$hdrs->removeAll('references');
            $hdrs->removeAll('in-reply-to');
            if(isset($message_headers['in-reply-to'])) {
                @$in_reply_to = $message_headers['in-reply-to'];
			    $hdrs->addTextHeader('References', $in_reply_to);
			    $hdrs->addTextHeader('In-Reply-To', $in_reply_to);
			} else {
                @$msgid = $message_headers['message-id'];
			    $hdrs->addTextHeader('References', $msgid);
			    $hdrs->addTextHeader('In-Reply-To', $msgid);
            }
				
			$hdrs->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
			$hdrs->addTextHeader('Precedence','List');
			$hdrs->addTextHeader('Auto-Submitted','auto-generated');
				
			$mail->setBody($message->getContent());					
				
			$result = $mailer->send($mail);
					
		} catch(Exception $e) {
            echo "Ticket Email Notification failed to send<br>";
		}
	}
    
	private function _workerAssignedTask($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

		$worker_id = $event->params['worker_id'];
		$context = $event->params['context'];
		$task_id = $event->params['context_id'];
		
        $mail_service = DevblocksPlatform::getMailService();
        $mailer = null; // lazy load
        
        $settings = DevblocksPlatform::getPluginSettingsService();
        $reply_to = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM, CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
        $reply_personal = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_PERSONAL, CerberusSettingsDefaults::DEFAULT_REPLY_PERSONAL);

        $task = DAO_Task::get($task_id);
        
		// Sanitize and combine all the destination addresses
		$next_worker = DAO_Worker::get($worker_id);
        $notify_emails = $next_worker->email;
			
		if(empty($notify_emails))
			return;
			
		try {
			if(null == $mailer)
				$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
					


		 	// Create the message
			$mail = $mail_service->createMessage();
			$mail->setTo(array($notify_emails));
			$mail->setFrom(array($reply_to => $reply_personal));
			$mail->setReplyTo($reply_to);
			$mail->setSubject(sprintf("[Task Assignment #%d]: %s",
				$task->id,
				$task->title
			));
				
			$headers = $mail->getHeaders();
            
			$headers->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
			$headers->addTextHeader('Precedence','List');
			$headers->addTextHeader('Auto-Submitted','auto-generated');
				
            $body = sprintf("[Task Assignment #%d]: %s",
				$task->id,
				$task->title
			);
            $mft = DevblocksPlatform::getExtension($context, false, true);
            $ext = $mft->createInstance();	
			$url = $ext->getPermalink($task_id);
            $body .= "\r\n" . $url;
            // Comments
            $comments = DAO_Comment::getByContext(CerberusContexts::CONTEXT_TASK, $task_id);
            foreach($comments as $comment_id => $comment) {
                $address = DAO_Address::get($comment->address_id);
                $body .= "\r\nCommented By: " . $address->first_name . " " . $address->last_name;
                $body .= "\r\n" . $comment->comment;
            }
            unset($comments);
            $body .= "\r\n";
            $mail->setBody($body);
				
			$result = $mailer->send($mail);
					
		} catch(Exception $e) {
            echo "Task Email Notification failed to send<br>";
		}
	}
        
	private function _sendForwards($event, $is_inbound) {
		@$ticket_id = $event->params['ticket_id'];
		@$send_worker_id = $event->params['worker_id'];
    	
		$url_writer = DevblocksPlatform::getUrlService();
		
		$ticket = DAO_Ticket::get($ticket_id);

		// (Action) Forward Email To:
		
		// Sanitize and combine all the destination addresses
		$context_workers = CerberusContexts::getWorkers(CerberusContexts::CONTEXT_TICKET, $ticket->id);
		if(!is_array($context_workers))
			return;
		foreach($context_workers as $next_worker) {
			$notify_emails = $next_worker->email;
			
			if(empty($notify_emails))
				continue;
		}

		// [TODO] This could be more efficient
		$messages = DAO_Message::getMessagesByTicket($ticket_id);
		$message = end($messages); // last message
		unset($messages);
		$headers = $message->getHeaders();
			
			// The whole flipping Swift section needs wrapped to catch exceptions
		try {
			$settings = DevblocksPlatform::getPluginSettingsService();
			$reply_to = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM,CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
			
			// See if we need a group-specific reply-to
			if(!empty($ticket->team_id)) {
				@$group_from = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_FROM, '');
				if(!empty($group_from))
					$reply_to = $group_from;
			}
			
			$sender = DAO_Address::get($message->address_id);
	
			$sender_email = strtolower($sender->email);
			$sender_split = explode('@', $sender_email);
	
			if(!is_array($sender_split) || count($sender_split) != 2)
				return;
	
			// If return-path is blank
			if(isset($headers['return-path']) && $headers['return-path'] == '<>')
				return;
				
				// Ignore bounces
			if($sender_split[0]=="postmaster" || $sender_split[0] == "mailer-daemon")
				return;
			
			// Ignore autoresponses autoresponses
			if(isset($headers['auto-submitted']) && $headers['auto-submitted'] != 'no')
				return;
				
			// Attachments
			$attachments = $message->getAttachments();
			$mime_attachments = array();
			if(is_array($attachments))
			foreach($attachments as $attachment) {
				if(0 == strcasecmp($attachment->display_name,'original_message.html'))
					continue;
				
				$attachment_path = APP_STORAGE_PATH . '/attachments/'; // [TODO] This is highly redundant in the codebase
				if(!file_exists($attachment_path . $attachment->filepath))
					continue;
				
				$attach = Swift_Attachment::fromPath($attachment_path . $attachment->filepath);
				if(!empty($attachment->display_name))
					$attach->setFilename($attachment->display_name);
				$mime_attachments[] = $attach;
			}
	    	
			// Send copies
			$mail_service = DevblocksPlatform::getMailService();
			$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
				
			$mail = $mail_service->createMessage(); /* @var $mail Swift_Message */
			$mail->setTo(array($notify_emails));
			$mail->setFrom(array($sender->email));
			$mail->setReplyTo($reply_to);
			$mail->setReturnPath($reply_to);
			$mail->setSubject(sprintf("[RW: %s #%s]: %s",
				($is_inbound ? 'inbound' : 'outbound'),
				$ticket->mask,
				$ticket->subject
			));

			$hdrs = $mail->getHeaders();
			
			if(null !== (@$msgid = $headers['message-id'])) {
				$hdrs->addTextHeader('Message-Id',$msgid);
			}
				
			if(null !== (@$in_reply_to = $headers['in-reply-to'])) {
			    $hdrs->addTextHeader('References', $in_reply_to);
			    $hdrs->addTextHeader('In-Reply-To', $in_reply_to);
			}
			
			$hdrs->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
			$hdrs->addTextHeader('Precedence','List');
			$hdrs->addTextHeader('Auto-Submitted','auto-generated');
			
			$mail->setBody($message->getContent());
	
			// Send message attachments with watcher
			if(is_array($mime_attachments))
			foreach($mime_attachments as $mime_attachment) {
				$mail->attach($mime_attachment);
			}
				
			$result = $mailer->send($mail);
		} catch(Exception $e) {
			if(!empty($message_id)) {
				$fields = array(
					DAO_MessageNote::MESSAGE_ID => $message_id,
					DAO_MessageNote::CREATED => time(),
					DAO_MessageNote::WORKER_ID => 0,
					DAO_MessageNote::CONTENT => 'Exception thrown while sending watcher email: ' . $e->getMessage(),
					DAO_MessageNote::TYPE => Model_MessageNote::TYPE_ERROR,
				);
				DAO_MessageNote::create($fields);
			}
		}
	}
};

