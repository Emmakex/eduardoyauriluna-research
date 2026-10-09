# Research Manager tests

These files contain focused runtime assertions intended to be executed inside a bootstrapped WordPress test/install environment.

`test-greenfield-mode.php` verifies that Research defaults to Greenfield, that migration capabilities are not accidentally enabled in Greenfield, that Migration can be selected explicitly, and that invalid persisted modes fail safely back to Greenfield.

The lightweight Greenfield GitHub workflow performs syntax and static contract checks. Runtime assertions can be included in the existing clean-WordPress integration harness when that harness is expanded for this milestone.
