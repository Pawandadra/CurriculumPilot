<?php

$string['pluginname'] = 'CurriculumPilot';

$string['apibaseurl'] = 'Service URL';
$string['apibaseurl_desc'] = 'The web address this site uses to reach the CurriculumPilot service. Your CurriculumPilot administrator normally supplies this value.';
    
$string['apitoken'] = 'Organisation access key';
$string['apitoken_desc'] = 'Enter your organisation’s access key here.';

$string['error_api_unauthorized'] = 'CurriculumPilot could not create lessons and question bank content for this import. Your organisation does not have access to this service. Please contact your administrator.';

$string['generateheading'] = 'Create lessons from a book';
$string['generate'] = 'Generate';

$string['capability_generate'] = 'Use CurriculumPilot in a course: upload a book and table of contents to generate lessons.';

$string['generatetoclabel'] = 'Table of contents file (PDF or image)';
$string['generatebooklabel'] = 'Book PDF';
$string['generatebooknote'] = 'Use the main body of the book only—do not include the same table-of-contents pages in this file.';

$string['generateqbankhint'] = 'Multiple-choice questions are also copied into this course’s question bank so you can add them to quizzes.';

$string['qbankactivityname'] = 'CurriculumPilot question bank';

$string['uploadfailed'] = 'Upload failed';
$string['uploaderr_ini'] = 'One of the files is larger than this site allows. Try smaller files, or ask your administrator to raise the site’s upload limits.';
$string['uploaderr_form'] = 'The selected file is larger than this page allows.';
$string['uploaderr_partial'] = 'The upload did not finish. Please try again.';
$string['uploaderr_nofile'] = 'No file was received. Please try again.';
$string['uploaderr_disk'] = 'The site could not store your upload. Please try again in a moment, or contact your administrator if this continues.';
$string['uploaderr_ext'] = 'The upload could not be accepted. Please contact your administrator.';
$string['uploaderr_unknown'] = 'Something went wrong while uploading. Please try again.';

$string['statusheading'] = 'Import status';
$string['statusviewall'] = 'All imports for this course';
$string['statusnotfound'] = 'We could not find details for this import.';
$string['statusitemid'] = 'Reference: {$a}';
$string['statuslabel'] = 'Status: {$a}';
$string['statustimes'] = 'Started: {$a->created}. Last update: {$a->modified}.';
$string['statuscompletehint'] = 'Your new lessons should now appear in this course. Open the course to review them.';
$string['statuspollhint'] = 'Your import is still running. This page will refresh automatically while you wait.';
$string['statusretry'] = 'Try again';
$string['backtocourse'] = 'Back to course';
$string['statusnone'] = 'No book imports have been recorded for this course yet.';
$string['statuscoltime'] = 'Started';
$string['statuscolitem'] = 'Reference';
$string['statuscolstate'] = 'Status';
$string['statusview'] = 'Details';

$string['status_queued'] = 'Waiting to start';
$string['status_processing'] = 'In progress';
$string['status_complete'] = 'Complete';
$string['status_failed'] = 'Could not complete';

$string['index_needcourse'] = 'Open the book import from within a course, or ask your administrator for a link.';

$string['error_apinotconfigured'] = 'CurriculumPilot is not fully configured on this site yet. Please contact your administrator.';

$string['error_nostoredfiles'] = 'Your uploads could not be found. Please start the import again.';
$string['error_copyfiles'] = 'Your files could not be prepared for processing. Please try again, or contact your administrator.';
$string['error_import_service'] = 'The CurriculumPilot service could not complete this import right now. Please try again in a little while, or contact your administrator.';
$string['error_invalid_response'] = 'CurriculumPilot returned something we could not use to build your lessons. Please try again, or contact your administrator.';
$string['error_import_unexpected'] = 'Something unexpected went wrong while creating your lessons. Please try again, or contact your administrator.';
