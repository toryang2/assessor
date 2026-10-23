package com.rameses.gov.etracs.rptis.models;
        
import com.rameses.rcp.common.*;
import com.rameses.rcp.annotations.*;
import com.rameses.osiris2.client.*;
import com.rameses.osiris2.common.*;
        
public class ReprintFAASModel {
    @Service('LogService')
    def logSvc;

    def entity = [:];

    def getLookupFaas() {
        return Inv.lookupOpener('faas:lookup', [
            onselect: {
                if (!it.state.matches('CURRENT|CANCELLED')) {
                    def msg = 'Selected FAAS is invalid.\n'
                    msg += 'Only current and cancelled record is allowed.'
                    throw new Exception(msg)
                }
                entity.faas = it;
            },
            onempty: {
                entity.faas = null;
            }
        ])
    }

    def afterPrint = {
        logSvc.log( 'reprint-original', 'FAAS', entity.faas.objid );
    }

    def preview() {
        return Inv.lookupOpener('faas:report', [
            entity: entity.faas,
            printAsOriginal: true,
            afterPrint: afterPrint,
        ]);
    }


            
}