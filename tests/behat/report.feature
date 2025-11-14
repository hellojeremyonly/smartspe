@mod_smartspe @javascript @report
Feature: Teacher views report for a team after archiving a form
  In order to see the report for a team after archiving a form
  As a teacher
  I need to archive the form and select the team from the dropdown to view the report

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | s1       | Alex      | Tan      | s1@example.com       |
      | s2       | Bea       | Lee      | s2@example.com       |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | s1       | C1     | student        |
      | s2       | C1     | student        |
    And the following "groups" exist:
      | name   | course | idnumber |
      | Team A | C1     | team_a   |
    And the following "group members" exist:
      | user | course | group  |
      | s1   | C1     | team_a |
      | s2   | C1     | team_a |
    And the following "activities" exist:
      | activity | course | name                | idnumber  |
      | smartspe | C1     | Test SmartSpe name  | smartspe1 |

    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I click on "Create new form" "link"
    And I set the following fields to these values:
      | Title      | Form 1                        |
      | Details    | Student Number                |
      | Question 1 | How well did you perform?     |
      | Type       | Likert scale (1–5)            |
      | Audience   | Self and peer evaluation form |
    And I press "Save changes"
    And I click on "Publish" "link" in the "region-main" "region"
    And I click on ".modal.show .modal-footer .btn-primary" "css_element"
    And I should see "Form has been published."
    And I log out

    When I log in as "s1"
    And I am on "C1" course homepage
    And I follow "Test SmartSpe name"
    And I should see "Student Details"
    And I set the field "Student Number" to "12345"
    And I press "id_submitbutton"
    And I press "Save and continue"
    And I press "Submit"
    And I log out

  Scenario: Teacher archived a form, and select team from dropdown to see report
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I should see "Form 1"
    And I click on "Archive" "link" in the "region-main" "region"
    And I should see "Are you sure you want to archive this form?"
    And I should see "Cancel"
    And I should see "Yes"
    And I click on ".modal.show [data-action='save']" "css_element"
    And I should see "Form has been archived."
    And I should see "Select a form to view submissions"

    Then "View submissions" "link" should not exist
    And "//button[normalize-space(.)='View submissions' and @disabled]" "xpath_element" should exist

    Then "select[name='formid']" "css_element" should exist
    And I set the field "formid" to "Form 1"
    And I wait to be redirected

    Then "//button[normalize-space(.)='View submissions' and @disabled]" "xpath_element" should not exist
    And "View submissions" "link" should exist

    Then I should not see "Select a form to view submissions"
    And I should see "Please select a team to view the report."

    Then "Export CSV" "link" should not exist
    And "//button[normalize-space(.)='Export CSV' and @disabled]" "xpath_element" should exist

    Then "select[name='teamid']" "css_element" should exist
    And I set the field "teamid" to "Team A"
    And I wait to be redirected

    Then "//button[normalize-space(.)='Export CSV' and @disabled]" "xpath_element" should not exist
    And "Export CSV" "link" should exist

    Then I should see "Team"
    And I should see "Student ID"
    And I should see "Surname"
    And I should see "Title"
    And I should see "Given Name"

    Then "select[name='formid']" "css_element" should exist
    And I set the field "formid" to "Form 1"
    And I wait to be redirected
    Then "select[name='teamid']" "css_element" should exist
    And I set the field "teamid" to "Team A"
    And I wait to be redirected

    And "View submissions" "link" should exist
    When I follow "View submissions"
    Then "table" "css_element" should exist
    And I should see "Submission List"
