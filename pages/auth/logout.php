<?php
/** Sign-out is a POST with a CSRF token so other sites cannot log users out via a link/image. */
if (!is_post()) {
    redirect('index.php');
}
verify_csrf();
audit('auth.logout', 'user', user_id());
end_user_session();
session_start();
flash('success', 'You have been signed out.');
redirect(url('login'));
