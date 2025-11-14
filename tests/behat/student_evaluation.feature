@mod_smartspe @student_evaluation
Feature: Student journey across a published SmartSPE form
  In order to complete a SmartSPE activity
  As a student
  I need to access Student Details, Self Evaluation, and Peer Evaluation pages

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | One      | teacher1@example.com  |
      | s1       | Alex      | Tan      | s1@example.com        |
      | s2       | Bea       | Lee      | s2@example.com        |
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

  Scenario: Student can access all student pages once the form is published
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I should see "Create new form"
    And I click on "Create new form" "link"
    And I set the following fields to these values:
      | Title      | Form 1                        |
      | Details    | Student Number                |
      | Question 1 | How well did you perform?     |
      | Type       | Likert scale (1–5)            |
      | Audience   | Self and peer evaluation form |
    And I press "Save changes"
    And I click on "Publish" "link" in the "region-main" "region"
    Then I should see "Form has been published"
    And I log out

    When I log in as "s1"
    And I am on "C1" course homepage
    And I follow "Test SmartSpe name"
    Then I should see "Instructions"
    And I should see "Student Details"
    And I set the field "Student Number" to "12345"
    And I should see "id_submitbutton"
    And I should see "Cancel"

    When I press "id_submitbutton"
    Then I should see "Instructions"
    And I should see "Self Evaluation"
    And I should see "How well did you perform?"
    And I should see "Back"
    And I should see "Save draft"
    And I should see "Save and continue"

    When I press "Save and continue"
    Then I should see "Instructions"
    And I should see "Peer Evaluation"
    And I should see "Bea Lee"
    And I should see "How well did you perform?"
    And I should see "Back"
    And I should see "Save draft"
    And I should see "Submit"

    When I press "Submit"
    Then I should see "Submission successful. Thank you for completing the evaluation."
    And I am on "Course 1" course homepage
