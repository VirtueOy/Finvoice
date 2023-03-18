<?php
namespace Finvoice;

class Finvoice {
    
    private $id         = null;
    private $settings   = null;
    private $xml        = null;
    private $timestamp  = null;
    private $namespaces = [
        'SOAP-ENV' => 'http://schemas.xmlsoap.org/soap/envelope/',
        'eb'       => 'http://www.oasis-open.org/committees/ebxml-msg/schema/msg-header-2_0.xsd',
        'xlink'    => 'http://www.w3.org/1999/xlink',
        'xsi'      => 'http://www.w3.org/2001/XMLSchema-instance'
    ];
    
    public function __construct(FinvoiceSettings $settings, $envelope = true) {
        $this->id = md5(rand() * time());
        $this->settings = $settings;
        $this->xml = new \SimpleXMLElement('<root/>');
        $this->timestamp = date('c');
        if($envelope) {
            $this->xml = $this->append($this->xml, $this->getEnvelope());
        }
        if(!empty($this->settings->invoice)) {
            $this->xml = $this->append($this->xml, $this->getFinvoice());
        }
    }
    
    private function float($float) {
        return (float)str_replace(',', '.', $float);
    }
    
    public function parse($xml_str) {
        
        $xml_str = preg_replace('~<(\?xml|!DOCTYPE).*?>~', '', $xml_str);
        
        $xml = new SimpleXMLElement('<Container>'.$xml_str.'</Container>');
        
        $return = [];
        
        if(isset($xml->Finvoice)) {
            $settings = new FinvoiceSettings();
            if(!is_array($xml->Finvoice)) {
                $finvoices = [$xml->Finvoice];
            } else {
                $finvoices = $xml->Finvoice;
            }
            
            foreach($finvoices as $finvoice) {
                $settings->from = (object)[
                    'IBAN'        => (string)$finvoice->SellerInformationDetails->SellerAccountDetails[0]->SellerAccountID,
                    'BIC'         => (string)$finvoice->SellerInformationDetails->SellerAccountDetails[0]->SellerBic,
                    'name'        => (string)$finvoice->SellerPartyDetails->SellerOrganisationName,
                    'business_id' => (string)$finvoice->SellerPartyDetails->SellerPartyIdentifier,
                    'address'     => (string)$finvoice->SellerPartyDetails->SellerPostalAddressDetails->SellerStreetName,
                    'postcode'    => (string)$finvoice->SellerPartyDetails->SellerPostalAddressDetails->SellerPostCodeIdentifier,
                    'city'        => (string)$finvoice->SellerPartyDetails->SellerPostalAddressDetails->SellerTownName
                ];
                $settings->to = (object)[
                    'IBAN'        => null,
                    'BIC'         => null,
                    'name'        => (string)$finvoice->BuyerPartyDetails->BuyerOrganisationName,
                    'business_id' => null,
                    'address'     => (string)$finvoice->BuyerPartyDetails->BuyerPostalAddressDetails->BuyerStreetName,
                    'postcode'    => (string)$finvoice->BuyerPartyDetails->BuyerPostalAddressDetails->BuyerPostCodeIdentifier,
                    'city'        => (string)$finvoice->BuyerPartyDetails->BuyerPostalAddressDetails->BuyerTownName
                ];
                
                $settings->invoice = (object)[
                    'id'               => (string)$finvoice->EpiDetails->EpiPaymentInstructionDetails->EpiPaymentInstructionId,
                    'date'             => date('Y-m-d', strtotime($finvoice->EpiDetails->EpiIdentificationDetails->EpiDate)),
                    'due_date'         => date('Y-m-d', strtotime($finvoice->EpiDetails->EpiPaymentInstructionDetails->EpiDateOptionDate)),
                    'reference_number' => (string)$finvoice->EpiDetails->EpiPaymentInstructionDetails->EpiRemittanceInfoIdentifier,
                    'amount'           => self::float((string)$finvoice->EpiDetails->EpiPaymentInstructionDetails->EpiInstructedAmount),
                    'rows'             => []
                ];
                
                if(isset($finvoice->InvoiceRow)) {
                    foreach($finvoice->InvoiceRow as $row) {
                        if(isset($row->SubInvoiceRow)) continue;
                        
                        $priceExcluded = self::float((string)$row->RowVatExcludedAmount);
                        $priceIncluded = self::float((string)$row->RowAmount);
                        $vat = self::float((string)$row->RowVatAmount);
                        $vatPercent = $priceExcluded > 0 ? round($vat / $priceExcluded * 100) : 0;
                        
                        $settings->invoice->rows[] = (object)[
                            'id'     => (int)$row->ArticleIdentifier,
                            'name'   => (string)$row->ArticleName,
                            'amount' => 1,
                            'unit'   => 'kpl',
                            'price'  => $priceExcluded,
                            'vat'    => $vatPercent
                        ];
                    }
                }
            }
            $return[] = new self($settings);
        }
        return $return;
    }
    
    public function addInvoice(FinvoiceSettings $settings) {
        $this->id = md5(rand() * time());
        $this->timestamp = date('c');
        $this->settings = $settings;
        $this->xml = $this->append($this->xml, $this->getFinvoice());
    }
    
    public function __toString() {
        return $this->getXML();
    }
    
    public function getTo($key = null) {
        if(empty($key)) {
            return $this->settings->to;
        } else {
            return isset($this->settings->to->$key) ? $this->settings->to->$key : null;
        }
    }
    
    public function getFrom($key = null) {
        if(empty($key)) {
            return $this->settings->from;
        } else {
            return isset($this->settings->from->$key) ? $this->settings->from->$key : null;
        }
    }
    
    public function getInvoice($key = null) {
        if(empty($key)) {
            return $this->settings->invoice;
        } else {
            return isset($this->settings->invoice->$key) ? $this->settings->invoice->$key : null;
        }
    }
    
    public function getXML() {
        $xml = empty($xml) ? $this->xml : $xml;
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xml = $dom->saveXML();
        $xml = str_replace(['<Envelope', '</Envelope', '<?xml version="1.0"?>'], ['<SOAP-ENV:Envelope', '</SOAP-ENV:Envelope', ''], $xml);
        $xml = preg_replace('~</?root>~', '', $xml);
        $xml = preg_replace('~^  ~m', '', $xml);
        $xml = preg_replace("~\n+~", "\n", $xml);
        $xml = trim($xml);
        $xml = preg_replace('~<Finvoice~', "<?xml version=\"1.0\" encoding=\"ISO-8859-15\"?>\n<?xml-stylesheet href=\"/vendor/virtueoy/finvoice/src/Finvoice.xsl\" type=\"text/xsl\"?>\n<Finvoice", $xml, 1);
        return $xml;
    }
    
    private function append($a, $b) {
        $dom = dom_import_simplexml($a);
        $child = dom_import_simplexml($b);
        $child = $dom->ownerDocument->importNode($child, true);
        $dom->appendChild($child);
        return $a;
    }
    
    private function getEnvelope() {
        
        $envelope = new \SimpleXMLElement('<Envelope/>');#, LIBXML_NOERROR, false, 'SOAP-ENV', true);
        
        $header = $envelope->addChild('SOAP-ENV:Header', null, $this->namespaces['SOAP-ENV']);
        $messageHeader = $header->addChild('eb:MessageHeader', null, $this->namespaces['eb']);
        
        $from = $messageHeader->addChild('From');
        $from->addChild('PartyId', $this->settings->from->IBAN);
        $from->addChild('Role', 'Sender');
        
        $from = $messageHeader->addChild('From');
        $from->addChild('PartyId', $this->settings->from->BIC);
        $from->addChild('Role', 'Intermediator');
        
        $to = $messageHeader->addChild('To');
        $to->addChild('PartyId', $this->settings->to->IBAN);
        $to->addChild('Role', 'Receiver');
        
        $to = $messageHeader->addChild('To');
        $to->addChild('PartyId', $this->settings->to->BIC);
        $to->addChild('Role', 'Intermediator');
        
        $messageHeader->addChild('CPAId', 'yoursandmycpa');
        $messageHeader->addChild('ConversationId');
        $messageHeader->addChild('Service', 'Routing');
        $messageHeader->addChild('Routing', 'ProcessInvoice');
        
        $messageData = $messageHeader->addChild('MessageData');
        $messageData->addChild('MessageId', $this->id);
        $messageData->addChild('Timestamp', $this->timestamp);
        $messageData->addChild('RefToMessageId');
        
        $body = $envelope->addChild('SOAP-ENV:Body', null, $this->namespaces['SOAP-ENV']);
        $manifest = $body->addChild('eb:Manifest', null, $this->namespaces['eb']);
        $manifest->addAttribute('eb:version', '2.0', $this->namespaces['eb']);
        $reference = $manifest->addChild('Reference', null);
        $reference->addAttribute('xlink:href', $this->id, $this->namespaces['xlink']);            
        $schema = $reference->addChild('Schema');
        $schema->addAttribute('eb:location', 'http://www.finvoice.info/finvoice.xsd', $this->namespaces['eb']);
        $schema->addAttribute('eb:version', '2.0', $this->namespaces['eb']);
        
        return $envelope;
    }
    
    public function getFinvoice() {
        $finvoice = new \SimpleXMLElement('<Finvoice/>');
        $finvoice->addAttribute('Version', '2.0');
        $finvoice->addAttribute('xsi:noNamespaceSchemaLocation', 'Finvoice.xsd', $this->namespaces['xsi']);
        
        $messageTransmissionDetails = $finvoice->addChild('MessageTransmissionDetails');
        
        $messageSenderDetails = $messageTransmissionDetails->addChild('MessageSenderDetails');
        $messageSenderDetails->addChild('FromIdentifier', $this->settings->from->IBAN);
        $messageSenderDetails->addChild('FromIntermediator', $this->settings->from->BIC);
        
        $messageReceiverDetails = $messageTransmissionDetails->addChild('MessageReceiverDetails');
        $messageReceiverDetails->addChild('ToIdentifier', $this->settings->to->IBAN);
        $messageReceiverDetails->addChild('ToIntermediator', $this->settings->to->BIC);
        
        $messageDetails = $messageTransmissionDetails->addChild('MessageDetails');
        $messageDetails->addChild('MessageIdentifier', $this->id);
        $messageDetails->addChild('MessageTimeStamp', $this->timestamp);
        
        $sellerPartyDetails = $finvoice->addChild('SellerPartyDetails');
        $sellerPartyDetails->addChild('SellerPartyIdentifier', $this->settings->from->business_id);
        $sellerPartyDetails->addChild('SellerOrganisationName', $this->settings->from->name);
        $sellerPostalAddressDetails = $sellerPartyDetails->addChild('SellerPostalAddressDetails');
        
        $sellerPostalAddressDetails->addChild('SellerStreetName', $this->settings->from->address);
        $sellerPostalAddressDetails->addChild('SellerTownName', $this->settings->from->city);
        $sellerPostalAddressDetails->addChild('SellerPostCodeIdentifier', $this->settings->from->postcode);
        $sellerPostalAddressDetails->addChild('CountryCode', 'FI');
        
        $finvoice->addChild('SellerOrganisationUnitNumber', '0037'.str_replace('-', '', $this->settings->from->business_id).'000001');
        $finvoice->addChild('SellerContactPersonName', isset($this->settings->from->contact) ? $this->settings->from->contact : null);
        
        $sellerCommunicationDetails = $finvoice->addChild('SellerCommunicationDetails');
        $sellerCommunicationDetails->addChild('SellerPhoneNumberIdentifier', isset($this->settings->from->phone) ? $this->settings->from->phone : null);
        $sellerCommunicationDetails->addChild('SellerEmailaddressIdentifier', isset($this->settings->from->email) ? $this->settings->from->email : null);
        
        $sellerInformationDetails = $finvoice->addChild('SellerInformationDetails');
        $sellerAccountDetails = $sellerInformationDetails->addChild('SellerAccountDetails');
        $sellerAccountDetails->addChild('SellerAccountID', $this->settings->from->IBAN)->addAttribute('IdentificationSchemeName', 'IBAN');
        $sellerAccountDetails->addChild('SellerBic', $this->settings->from->BIC)->addAttribute('IdentificationSchemeName', 'BIC');
        
        $buyerPartyDetails = $finvoice->addChild('BuyerPartyDetails');
        $buyerPartyDetails->addChild('BuyerPartyIdentifier', $this->settings->to->business_id);
        $buyerPartyDetails->addChild('BuyerOrganisationName', htmlspecialchars($this->settings->to->name));
        $buyerPartyDetails->addChild('BuyerOrganisationTaxCode', empty($this->settings->to->business_id) ? '' : 'FI'.str_replace('-', '', $this->settings->to->business_id));
        
        $buyerPostalAddressDetails = $buyerPartyDetails->addChild('BuyerPostalAddressDetails');
        $buyerPostalAddressDetails->addChild('BuyerStreetName', $this->settings->to->address);
        $buyerPostalAddressDetails->addChild('BuyerTownName', $this->settings->to->city);
        $buyerPostalAddressDetails->addChild('BuyerPostCodeIdentifier', $this->settings->to->postcode);
        $buyerPostalAddressDetails->addChild('CountryCode', 'FI');
        $buyerPostalAddressDetails->addChild('CountryName', 'Suomi');
        
        if(!empty($this->settings->delivery)) {
            $deliveryPartyDetails = $finvoice->addChild('DeliveryPartyDetails');
            $deliveryPartyDetails->addChild('DeliveryPartyIdentifier', isset($this->settings->delivery->business_id) ? $this->settings->delivery->business_id : null);
            $deliveryPartyDetails->addChild('DeliveryOrganisationName', $this->settings->delivery->name);
            $deliveryPartyDetails->addChild('DeliveryOrganisationTaxCode', isset($this->settings->delivery->business_id) ? 'FI'.str_replace('-', '', $this->settings->delivery->business_id) : null);
            
            $deliveryPostalAddressDetails = $deliveryPartyDetails->addChild('DeliveryPostalAddressDetails');
            $deliveryPostalAddressDetails->addChild('DeliveryStreetName', $this->settings->delivery->address);
            $deliveryPostalAddressDetails->addChild('DeliveryTownName', $this->settings->delivery->city);
            $deliveryPostalAddressDetails->addChild('DeliveryPostCodeIdentifier', $this->settings->delivery->postcode);
            $deliveryPostalAddressDetails->addChild('CountryCode', 'FI');
            $deliveryPostalAddressDetails->addChild('CountryName', 'Suomi');
        }
        
        $finvoice->addChild('BuyerContactPersonName', isset($this->settings->to->contact) ? htmlspecialchars($this->settings->to->contact) : null);
        
        $invoiceDetails = $finvoice->addChild('InvoiceDetails');
        $invoiceDetails->addChild('InvoiceTypeCode', 'INV01')->addAttribute('CodeListAgencyIdentifier', 'SPY');
        $invoiceDetails->addChild('InvoiceTypeText', 'LASKU');
        $invoiceDetails->addChild('OriginCode', 'Original');
        $invoiceDetails->addChild('InvoiceNumber', $this->settings->invoice->id);
        $invoiceDetails->addChild('InvoiceDate', date('Ymd', strtotime($this->settings->invoice->date)))->addAttribute('Format', 'CCYYMMDD');
        $invoiceDetails->addChild('SellerReferenceIdentifier', isset($this->settings->invoice->seller_reference) ? htmlspecialchars(substr($this->settings->invoice->seller_reference, 0, 35)) : null);
        if(!empty($this->settings->invoice->order_id)) {
                $invoiceDetails->addChild('OrderIdentifier', $this->settings->invoice->order_id);
        }
        $invoiceDetails->addChild('BuyerReferenceIdentifier', isset($this->settings->invoice->buyer_reference) ? htmlspecialchars(substr($this->settings->invoice->buyer_reference, 0, 35)) : null);

        
        $totalVatExcludedAmount = 0;
        $totalVatAmount = 0;
        $totalVatIncludedAmount = 0;
        
        $vat = [];
        
        foreach($this->settings->invoice->rows as $row) {
            $row = (object)$row;
            $vatExcludedAmount = $row->amount * $row->price_net;
            $vatIncludedAmount = $row->amount * $row->price_gross;
            $vatAmount         = $vatIncludedAmount - $vatExcludedAmount;
            
            if(!isset($vat[$row->vat])) {
                $vat[$row->vat] = (object)[
                    'vatBaseAmount' => 0,
                    'vatRatePercent' => $row->vat,
                    'vatRateAmount' => 0
                ];
            }
            
            $vat[$row->vat]->vatBaseAmount += $vatExcludedAmount;
            $vat[$row->vat]->vatRateAmount += $vatAmount;
            
            $totalVatExcludedAmount += $vatExcludedAmount;
            $totalVatAmount += $vatAmount;
            $totalVatIncludedAmount += $vatIncludedAmount;
        }
        
        $invoiceDetails->addChild('InvoiceTotalVatExcludedAmount', number_format($this->settings->invoice->price_net, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        $invoiceDetails->addChild('InvoiceTotalVatAmount', number_format($this->settings->invoice->price_gross - $this->settings->invoice->price_net, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        $invoiceDetails->addChild('InvoiceTotalVatIncludedAmount', number_format($this->settings->invoice->price_gross, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        foreach($vat as $vatInfo) {
            $vatSpecificationDetails = $invoiceDetails->addChild('VatSpecificationDetails');
            $vatSpecificationDetails->addChild('VatBaseAmount', number_format($vatInfo->vatBaseAmount, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
            $vatSpecificationDetails->addChild('VatRatePercent', $vatInfo->vatRatePercent);
            $vatSpecificationDetails->addChild('VatRateAmount', number_format($vatInfo->vatRateAmount, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        }
        $invoiceDetails->addChild('PaymentTermsDetails')->addChild('InvoiceDueDate', date('Ymd', strtotime($this->settings->invoice->due_date)))->addAttribute('Format', 'CCYYMMDD');
        
        foreach($this->settings->invoice->rows as $row) {
            
            $invoiceRow = $finvoice->addChild('InvoiceRow');

            $rowVatAmount         = $row->amount * ($row->price * ($row->vat / 100));
            $rowVatExcludedAmount = $row->amount * $row->price;
            $rowAmount            = $row->amount * ($row->price * (100 + $row->vat) / 100);
            
            $invoiceRow->addChild('ArticleName', htmlspecialchars($row->name));
            if(!empty($row->description)) {
                $rowDefinitionDetails = $invoiceRow->addChild('RowDefinitionDetails');
                $rowDefinitionDetails->addChild('RowDefinitionHeaderText', substr('Lisätieto', 0, 70));
                $rowDefinitionDetails->addChild('RowDefinitionValue', substr(htmlspecialchars($row->description), 0, 70));
            }

            if(isset($row->ordered)) {
                $invoiceRow->addChild('OrderedQuantity', number_format($row->ordered, 2, ',', ''))->addAttribute('QuantityUnitCode', $row->unit);
            }
            
            $invoiceRow->addChild('InvoicedQuantity', number_format($row->amount, 2, ',', ''))->addAttribute('QuantityUnitCode', $row->unit);
            $unitPriceAmount = $invoiceRow->addChild('UnitPriceAmount', number_format($row->price_net, 2, ',', ''));
            $unitPriceAmount->addAttribute('AmountCurrencyIdentifier', 'EUR');
            $unitPriceAmount->addAttribute('UnitPriceUnitCode', 'e/'.$row->unit);
            $invoiceRow->addChild('UnitPriceVatIncludedAmount', number_format($row->price_gross, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
            if($row->id > 0) {
                $invoiceRow->addChild('RowIdentifier', $row->id);
            }
            if(!empty($row->discount_relative)) {
                $invoiceRow->addChild('RowDiscountPercent', $row->discount_relative);
            }
            if(!empty($row->discount_absolute)) {
                $invoiceRow->addChild('RowDiscountAmount', number_format($row->discount_absolute, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
            }
            $invoiceRow->addChild('RowVatRatePercent', $row->vat);
            $invoiceRow->addChild('RowVatAmount', number_format($row->amount * ($row->price_gross - $row->price_net), 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
            $invoiceRow->addChild('RowVatExcludedAmount', number_format($row->amount * $row->price_net, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
            $invoiceRow->addChild('RowAmount', number_format($row->amount * $row->price_gross, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        }
        
        #$specificationDetails = $finvoice->addChild('SpecificationDetails');
        #$specificationDetails->addChild('BuyerReferenceIdentifier
        
        $epiDetails = $finvoice->addChild('EpiDetails');
        $epiIdentificationDetails = $epiDetails->addChild('EpiIdentificationDetails');
        $epiIdentificationDetails->addChild('EpiDate', date('Ymd', strtotime($this->settings->invoice->date)))->addAttribute('Format', 'CCYYMMDD');
        $epiIdentificationDetails->addChild('EpiReference', $this->settings->invoice->reference_number);
        
        $epiPartyDetails = $epiDetails->addChild('EpiPartyDetails');
        $epiBfiPartyDetails = $epiPartyDetails->addChild('EpiBfiPartyDetails');
        $epiBfiPartyDetails->addChild('EpiBfiIdentifier', $this->settings->from->BIC)->addAttribute('IdentificationSchemeName', 'BIC');
        
        $epiBeneficiaryPartyDetails = $epiPartyDetails->addChild('EpiBeneficiaryPartyDetails');
        $epiBeneficiaryPartyDetails->addChild('EpiNameAddressDetails', $this->settings->from->name);
        $epiBeneficiaryPartyDetails->addChild('EpiAccountID', $this->settings->from->IBAN)->addAttribute('IdentificationSchemeName', 'IBAN');
        
        $epiPaymentInstructionDetails = $epiDetails->addChild('EpiPaymentInstructionDetails');
        $epiPaymentInstructionDetails->addChild('EpiRemittanceInfoIdentifier', $this->settings->invoice->reference_number)->addAttribute('IdentificationSchemeName', 'SPY');
        $epiPaymentInstructionDetails->addChild('EpiInstructedAmount', number_format($this->settings->invoice->price_gross, 2, ',', ''))->addAttribute('AmountCurrencyIdentifier', 'EUR');
        $epiPaymentInstructionDetails->addChild('EpiCharge', 'SHA')->addAttribute('ChargeOption', 'SHA');
        $epiPaymentInstructionDetails->addChild('EpiDateOptionDate', date('Ymd', strtotime($this->settings->invoice->due_date)))->addAttribute('Format', 'CCYYMMDD');
        
        return $finvoice;
    }
    
    public function output($xml = null) {
        $xml = empty($xml) ? $this->xml : $xml;
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        header('Content-type: text/xml');
        echo $dom->saveXML();
    }
    
}
