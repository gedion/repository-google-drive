@repository @repository_googledrive @repository_googledrive_url @javascript
Feature: Adding Google Drive as a link in URL resource.

  Scenario: Adding URL resource
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course1   | c1        |
    And Google Drive repository is enabled
    And I log in as "admin"
    And I am on site homepage
    And I follow "Course1"
    And I turn editing mode on
    When I add a "URL" to section "1"
    And I set the following fields to these values:
      | Name        | Google Doc API url   |
      | Description | URL using google doc |
    And I press "Choose a link..."
    And I click on ".fp-repo-area li a img[src*='/repository_googledrive/']" "css_element"
    And I login to Google Drive
    And I click on ".fp-content img[title='Test Doc.rtf']" "css_element"
    And I press "Select this file"
    And I press "Save and return to course"
    And I follow "Google Doc API url"
    Then "#docs-drive-logo" "css_element" should exist
