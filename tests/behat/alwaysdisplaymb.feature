@report @report_coursesize
Feature: In a report, admin can see summary of course sizes

  Scenario: Verify Course size report show display as auto
    Given I log in as "admin"
    And I navigate to "Reports > Course size" in site administration
    Then I should see "Display sizes as:"

  Scenario: Verify Course size report show display as MB
    Given I log in as "admin"
    Given I set the following administration settings values:
      | Always display in MB | 1 |
    And I navigate to "Reports > Course size" in site administration
    Then I should not see "Display sizes as:"

