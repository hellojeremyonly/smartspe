@mod_smartspe
Feature: Form access control
  In order to control access to forms
  As a teacher
  I need to be able to publish and unpublish forms

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | One      | teacher1@example.com  |
      | student1 | Student   | One      | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name                | idnumber  |
      | smartspe | C1     | Test SmartSpe name | smartspe1 |

  Scenario: Teacher publishes the form
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I should see "Create new form"
    And I click on "Create new form" "link"
    And I set the following fields to these values:
      | Title | Form 1 |
    And I press "Save changes"
    When I click on "Publish" "link" in the "region-main" "region"
    Then I should see "Form has been published."

  Scenario: Student can access the form after publish
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I should see "Create new form"
    And I click on "Create new form" "link"
    And I set the following fields to these values:
      | Title | Form 1 |
    And I press "Save changes"
    And I click on "Publish" "link" in the "region-main" "region"
    Then I should see "Form has been published."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    Then I should see "Student Details"

  Scenario: Student is blocked after unpublish
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    And I click on "Create new form" "link"
    And I set the following fields to these values:
      | Title | Form 1 |
    And I press "Save changes"
    And I click on "Publish" "link" in the "region-main" "region"
    When I click on "Unpublish" "link" in the "region-main" "region"
    Then I should see "Form has been unpublished."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I follow "Test SmartSpe name"
    Then I should see "No published forms available."
    And I should not see "Student Details"
    And I should not see "Form Management"
