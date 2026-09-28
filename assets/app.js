import './stimulus_bootstrap.js';
// Stateless CSRF (authenticate/logout/submit) replaces the csrf-token placeholder on submit.
// Must load with the app entry; the Stimulus controller is lazy and would not register the listener.
import './controllers/csrf_protection_controller.js';
