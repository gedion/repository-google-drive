@repository @repository_googledrive @repository_googledrive_file @javascript
Feature: Adding Google Drive as a link or shortcut in File resource.

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course1   | c1        |
    And Google Drive repository is enabled
    And I log in as "admin"
    And I navigate to "Navigation" node in "Site administration > Appearance"
    And I am on site homepage
    And I follow "Preferences" in the user menu
    And I connect to Google Drive
    And I am on site homepage
    And I follow "Course1"
    And I turn editing mode on

  Scenario Outline: Creating shortcut with "Force download" option
    When I am on site homepage
    And I follow "Course1"
    And I add a "File" to section "1"
    # Choosing "Force download" with display input value
    And I set the following fields to these values:
      | Name        | Force download an alias/shortcut to a gdoc file |
      | Description | Force download an alias/shortcut to a gdoc file |
      | Display     | 4                                               |
    And I expand all fieldsets
    And I click on "#id_display" "css_element"
    And I click on "Force download" "option"
    And I press "Add..."
    And I click on ".fp-repo-area li a img[src*='/repository_googledrive/']" "css_element"
    And I click on ".fp-repo-items .fp-viewbar a.fp-vb-details" "css_element"
    And I click on "//td[contains(.,'<filename>')]" "xpath_element"
    And I click on ".file-picker input[name='linktype'][value='4']" "css_element"
    And I press "Select this file"
    And I press "Save and return to course"
    Then following "Force download an alias/shortcut to a gdoc file" should download between <filesize> bytes

    Examples:
      | filename                                 | filesize                 |
      | Test Doc.rtf                             | "60000" and "100000"     |
      | Test Slide.pptx                          | "60000" and "100000"     |
      | Test spreadsheet.csv                     | "60000" and "100000"     |
      | Hello word.doc                           | "20000" and "30000"      |
      | Hello word.docx                          | "20000" and "30000"      |
      | Hello xls.xls                            | "20000" and "30000"      |
      | MyWeight.xlsx                            | "50000" and "60000"      |
      | Screen Shot 2016-03-14 at 3.29.43 PM.png | "200000" and "300000"    |
      | crcaccounts.neocities.org_billstarr.pdf  | "60000" and "100000"     |

