<?php

/**
 *
 * AG Spam Finder
 *
 * @author Andrew McNaughton
 * @xyz
 *
 */
class CRM_Customsearches_Form_Search_addressFixNeeded extends CRM_Contact_Form_Search_Custom_Base implements CRM_Contact_Form_Search_Interface {

  protected $_formValues;

  function __construct(&$formValues) {
    $this->_formValues = $formValues;

    $this->_columns = array(
      ts('Contact Id') => 'contact_id',
      ts('Display Name') => 'display_name',
      ts('Address1') => 'address1',
    );
  }

  /**
   * Prepare a set of search fields
   *
   * @param CRM_Core_Form $form modifiable
   */
  function buildForm(&$form) {        $this->setTitle('AG - Find Users with Addresses Needing Fixing');
    /**
     * Define the search form fields here
     */
    $search_types = array(
      //'1' => 'Multiple billing addresses for a contact',
      '2' => 'Street address missing a number',
      '3'=> 'Has street address and postcode, but not city',
      '4' => 'Missing or invalid postcode',
      '5' => 'Missing state_province_id',
      '6' => 'Address contains punctuation',
      // '7' => 'Inconsistent addresses for combined mailings',
      '8' => 'Country is missing, or is one that is often entered incorrectly',
      '9' => 'State does not match Country',
      '10'=> 'Street Address contains "NCA" (NCA addresses are hidden from other searches)',
    );
    $form->addRadio('search_type',
      ts('Search options'),
      $search_types,
      TRUE, '<br />', TRUE
    );

    // Filter on Minimum Contact ID
    $form->add('text',
      'min_contact_id',
      ts('Only show contact records with ID greater than:')
    );

    /**
     * If you are using the sample template, this array tells the template fields to render
     * for the search form.
     */
    $form->assign('elements', array(
      'search_type', 'min_contact_id',
    ));
  }

  /**
   * Get a list of summary data points
   *
   * @return mixed; NULL or array with keys:
   *  - summary: string
   *  - total: numeric
   */
  function summary() {
    return NULL;
    // return array(
    //   'summary' => 'This is a summary',
    //   'total' => 50.0,
    // );
  }

  /**
   * Get a list of displayable columns
   *
   * @return array, keys are printable column headers and values are SQL column names
   */
  function &columns() {
    // return by reference
    $columns = array(
      ts('Contact Id') => 'contact_id',
      ts('Contact Type') => 'contact_type',
      ts('Name') => 'sort_name',
      ts('State') => 'state_province',
    );
    return $columns;
  }

  /**
   * Construct a full SQL query which returns one page worth of results
   *
   * @param int $offset
   * @param int $rowcount
   * @param null $sort
   * @param bool $includeContactIDs
   * @param bool $justIDs
   * @return string, sql
   */
  function all($offset = 0, $rowcount = 0, $sort = NULL, $includeContactIDs = FALSE, $justIDs = FALSE) {

    // SELECT clause must include contact_id as an alias for civicrm_contact.id
    if ($justIDs) {
      $select = "contact.id as contact_id";
    }
    else {
      $select = "
              contact.id as contact_id,
              contact.display_name  as display_name,
              concat(
                  IFNULL(address1.street_address,''), ' <br /> ',
                  IF(address1.supplemental_address_1 is NULL, '', concat(address1.supplemental_address_1, '<br />')),
                  IFNULL(address1.city,''), ' - ',
                  IF(state1.id IS NULL, '', state1.abbreviation), ' - ',
                  IFNULL(address1.postal_code,''), ' - ',
                  IF(country1.id IS NULL, '', country1.iso_code)
              ) as address1
              ";
    }

    $from = $this->from();
    $where = $this->where($includeContactIDs);

    $sql = "
          SELECT $select
          FROM $from\n";

    if (!empty($where)) {
      $sql .= "WHERE $where";
    }

    if ($rowcount > 0 && $offset >= 0) {
      $sql .= " LIMIT $offset, $rowcount ";
    }

    return $sql;
  }

  /**
   * Construct a SQL SELECT clause
   *
   * @return string, sql fragment with SELECT arguments
   */
  function select() {
    return "
      contact_a.id           as contact_id  ,
      contact_a.contact_type as contact_type,
      contact_a.sort_name    as sort_name,
      state_province.name    as state_province
    ";
  }

  /**
   * Construct a SQL FROM clause
   *
   * @return string, sql fragment with FROM and JOIN clauses
   */
  function from() {
    $sql = "civicrm_contact AS contact
          JOIN civicrm_address AS address1
            ON address1.contact_id = contact.id
            AND address1.location_type_id=5
          LEFT JOIN civicrm_state_province AS state1
            ON state1.id = address1.state_province_id
          LEFT JOIN civicrm_country AS country1
            ON country1.id = address1.country_id";

    return $sql;
  }

  /**
   * Construct a SQL WHERE clause
   *
   * @param bool $includeContactIDs
   * @return string, sql fragment with conditional expressions
   */
  function where($includeContactIDs = FALSE) {
    $clauses = array();

    $clauses []= '(not contact.is_deleted)';

    // add contact name search; search on primary name, source contact, assignee
    $search_type = $this->_formValues['search_type'];
    switch ($search_type) {
      case 1:
        // '1' => 'Multiple billing addresses for a contact',
        $clauses[]   = "()";
        break;
      case 2:
        // '2' => 'Street address missing a number',
        $clauses[]   = "(address1.street_address not rlike '[0-9]')";
        break;
      // case 3:
      //     // '3' => 'Missing city',
      //     $clauses[]   = "(address1.id is not null AND ( address1.city is null or address1.city = ''))";
      //     break;
      case 3:
        // '11'=> 'Has street address and postcode, but not city'
        $clauses[]   = "(((address1.street_address != '') AND (address1.postal_code != '')) AND (address1.city is null or address1.city = ''))";
        break;
      case 4:
        // '4' => 'Missing or invalid postcode',
        $clauses[]   = "((address1.country_id=1013 OR address1.country_id is NULL) and address1.postal_code NOT RLIKE '^[0-9]{4}$')";
        break;
      case 5:
        // '5' => 'Missing state_province_id',
        $clauses[]   = "((address1.country_id=1013 OR address1.country_id is NULL) AND address1.id is not null AND address1.state_province_id is null)";
        break;
      case 6:
        // '6' => 'Address contains punctuation',
        $clauses[]   = "(address1.street_address rlike '[^[:alnum:] /-]')";
        break;
      case 7:
        // '7' => 'Inconsistent addresses for combined mailings',
        $clauses[]   = "()";
        break;
      case 8:
        // '8' => 'Missing or Unlikely Country',
        $clauses[]   = "(address1.id is not null AND (address1.country_id is null OR address1.country_id in (1001, 1014)))";
        break;
      case 9:
        // '9' => 'State does not match Country',
        $clauses[]   = "(state1.id is not null AND NOT (state1.country_id = address1.country_id))";
        break;
      case 10:
        // '10'=> 'Street Address contains "NCA" (NCA addresses are hidden from other searches)',
        $clauses[]   = "(address1.street_address rlike 'NCA')";
        break;
    } // end switch
    if ($search_type != 10) {
      $clauses[]   = "NOT (address1.street_address rlike '[[:<:]]NCA[[:>:]]')";
    }



    $min_contact_id = $this->_formValues['min_contact_id'];
    if($min_contact_id) {
      $clauses []= "(contact.id > $min_contact_id)";
    }

    if ($includeContactIDs) {
      $contactIDs = array();
      foreach ($this->_formValues as $id => $value) {
        if ($value &&
          substr($id, 0, CRM_Core_Form::CB_PREFIX_LEN) == CRM_Core_Form::CB_PREFIX
        ) {
          $contactIDs[] = substr($id, CRM_Core_Form::CB_PREFIX_LEN);
        }
      }

      if (!empty($contactIDs)) {
        $contactIDs = implode(', ', $contactIDs);
        $clauses[] = "contact.id IN ( $contactIDs )";
      }
    }

    return implode(' AND ', $clauses);
  }

  /**
   * Determine the Smarty template for the search screen
   *
   * @return string, template path (findable through Smarty template path)
   */
  function templateFile() {
    return 'CRM/Contact/Form/Search/Custom.tpl';
  }

  /**
   * Modify the content of each row
   *
   * @param array $row modifiable SQL result row
   * @return void
   */
  function alterRow(&$row) {
    $row['sort_name'] .= ' ( altered )';
  }
}
