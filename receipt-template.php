<?php

class RefugeeConnect_receipt_template
{
    private $receipt;

    public function __construct($receipt, $emailAddress)
    {
        $this->receipt = $receipt;
        $this->emailAddress = $emailAddress ? "($emailAddress)" : "";


    }

    public function get_html()
    {
        return $this->header()
            . $this->body_wrapper()
            . $this->items()
            . $this->footer();
    }

    private function header()
    {
        return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    
    <style>
    .invoice-box{
        max-width:800px;
        margin:auto;
        padding:30px;
        border:1px solid #eee;
        box-shadow:0 0 10px rgba(0, 0, 0, .15);
        font-size:16px;
        line-height:24px;
        font-family:'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
        color:#555;
    }
    
    .invoice-box table{
        width:100%;
        line-height:inherit;
        text-align:left;
    }
    
    .invoice-box table td{
        padding:5px;
        vertical-align:top;
    }
    
    .invoice-box table.items tr td:nth-child(2){
        text-align:right;
    }
    
    .invoice-box table tr.top table td{
        padding-bottom:20px;
    }
    
    .invoice-box table tr.top table td.title{
        font-size:45px;
        line-height:45px;
        color:#333;
    }
    
    .invoice-box table tr.information table td{
        padding-bottom:40px;
    }
    
    .invoice-box table tr.heading td{
        background:#eee;
        border-bottom:1px solid #ddd;
        font-weight:bold;
    }
    
    .invoice-box table tr.details td{
        padding-bottom:20px;
    }
    
    .invoice-box table tr.item td{
        border-bottom:1px solid #eee;
    }
    
    .invoice-box table tr.item.last td{
        border-bottom:none;
    }
    
    .invoice-box table tr.total td:nth-child(2){
        border-top:2px solid #eee;
        font-weight:bold;
    }
    
    @media only screen and (max-width: 600px) {
        .invoice-box table tr.top table td{
            width:100%;
            display:block;
            text-align:center;
        }
        
        .invoice-box table tr.information table td{
            width:100%;
            display:block;
            text-align:center;
        }
    }
    </style>
</head>
HTML;

    }

    private function body_wrapper()
    {
        return <<<HTML
<div class="invoice-box">
<img src="https://refugeeconnect.org.au/wp-content/themes/refugee-connect-wordpress-theme/images/footer-logo.png" style="width: 85px; float: left; margin-right: -85px">
<h1 style="text-align: center">Tax Receipt<br/>Refugee Connect Ltd<br/>
<span style="font-size: x-small">Formerly: Helping Hands International Australia Ltd.</span>
</h1>

        <table class="receipt_details" cellpadding="0" cellspacing="0">
            <tr>
                <td>Receipt #</td><td>{$this->receipt->Id}</td>
            </tr>
            <tr>
                <td>Date</td><td>{$this->receipt->TxnDate}</td>
            </tr>
            <tr>
                <td>Donated By</td><td>{$this->receipt->CustomerRef->name} {$this->emailAddress}</td>
            </tr>            
            <tr>
                <td>Donation Type</td><td>Bank Deposit</td>
            </tr>
        </table>
        <table class="items">
            
            <tr class="heading">
                <td>
                    Item
                </td>
                
                <td>
                    Amount
                </td>
            </tr>
            
 
HTML;

    }

    private function items()
    {
        $lines_html = '';
        $lines = [];
        foreach ($this->receipt->Line as $line) {
            if ($line->Id) {
                $lines[] = $line;
            }
        }
        foreach ($lines as $line) {
            $last = "";
            if (!next($lines)) {
                $last = "last";
            }
            $lines_html .= <<<HTML
            <tr class="item $last">
                <td>
                    {$line->Description}
                </td>
    
                <td>
                    \${$line->SalesItemLineDetail->TaxInclusiveAmt}
                </td>
            </tr>
HTML;
        }
        // TODO format money properly
        return $lines_html . <<<HTML
            
            <tr class="total">
                <td></td>
                
                <td>
                   Total: \${$this->receipt->TotalAmt}
                </td>
            </tr>
HTML;
    }

    private function footer()
    {
        return <<<HTML
        </table>
        
        <h3 style="text-align: center">Thank you for your generosity. We appreciate your support!</h3>
        <p style="text-align: center; font-size: small">Refugee Connect Ltd is a Deductible Gift Recipient.<br/>
        Qld. Charity Registration number: CH2478<br/>ABN: 58 092 560 346<br/>
        <strong>Donations over $2 are tax deductible.</strong></p>
        <p style="text-align: center; font-weight: bold">Questions? Contact Ken on 0421 076 306 or <a href="mailto:admin@refugeeconnect.org.au">admin@refugeeconnect.org.au</a></p>
    </div>
HTML;
    }
}