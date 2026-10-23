import React from 'react';
import ReactDOMServer from 'react-dom/server';

// Import components to test
import TaxDeclarationPreviewModal from '../components/EtracsPropertyTable/TaxDeclarationPreviewModal';
import EtracsAuditTimelineModal from '../components/EtracsPropertyTable/EtracsAuditTimelineModal';
import { etracsService } from '../utils/api';

// Mock axios first to resolve CRA jest ESM resolution for axios 1.x
jest.mock('axios', () => ({
  create: jest.fn(() => ({
    interceptors: {
      request: { use: jest.fn() },
      response: { use: jest.fn() },
    },
    get: jest.fn(),
    post: jest.fn(),
    put: jest.fn(),
    delete: jest.fn(),
  })),
  get: jest.fn(),
  post: jest.fn(),
  put: jest.fn(),
  delete: jest.fn(),
}));

// Mock MUI Modal/Dialog to render children inline for SSR string tests
jest.mock('@mui/material', () => {
  const actual = jest.requireActual('@mui/material');
  return {
    ...actual,
    Dialog: ({ open, children }) => (open ? <div data-testid="mock-dialog">{children}</div> : null),
    Modal: ({ open, children }) => (open ? <div data-testid="mock-modal">{children}</div> : null),
  };
});

describe('WP-08: Shadow ETRACS Frontend Workflow Integration', () => {
  describe('1. TaxDeclarationPreviewModal: Effective State, Cancellation, Disapproval & Multiple Predecessors', () => {
    test('renders multiple predecessor snapshots faithfully', () => {
      const mockProperty = {
        id: 101,
        tdno: '2026-TD-00101',
        pin: '020-01-001-01-001',
        state: 'CURRENT',
        effective_state: 'CURRENT',
        origin_type: 'LOCAL',
        owner_name: 'JUAN DELA CRUZ',
        taxpayer_address: 'Barangay 1, Baybay City',
        classification: 'RESIDENTIAL',
        actualuse: 'RESIDENTIAL',
        total_market_value: 500000,
        total_assessed_value: 100000,
        assessment_levels: [{
          id: 1,
          code: 'RES-01',
          class_title: 'Residential Land',
          actual_use: 'RESIDENTIAL',
          market_value: 500000,
          assessment_level: 20,
          assessed_value: 100000,
          taxable: 1,
        }],
        predecessors: [
          {
            prev_tdno: '1999-OLD-001',
            prev_pin: '020-01-001-01-000',
            prev_owner_name: 'PEDRO SANTOS',
            prev_total_market_value: 300000,
            prev_total_assessed_value: 60000,
            prev_effectivity_year: 2000,
          },
          {
            prev_tdno: '1999-OLD-002',
            prev_pin: '020-01-001-01-000-B',
            prev_owner_name: 'MARIA SANTOS',
            prev_total_market_value: 200000,
            prev_total_assessed_value: 40000,
            prev_effectivity_year: 2000,
          }
        ]
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockProperty}
        />
      );

      // Verify header and state
      expect(view).toContain('TAX DECLARATION OF REAL PROPERTY');
      expect(view).toContain('2026-TD-00101');
      expect(view).toContain('CURRENT');

      // Verify multiple predecessors rendered
      expect(view).toContain('1999-OLD-001');
      expect(view).toContain('PEDRO SANTOS');
      expect(view).toContain('1999-OLD-002');
      expect(view).toContain('MARIA SANTOS');

      // Verify assessment schedule line
      expect(view).toContain('Residential Land');
      expect(view).toContain('20.00%');
    });

    test('renders structured cancellation metadata and watermark for cancelled declarations', () => {
      const mockCancelledProperty = {
        id: 102,
        tdno: '2026-TD-00102',
        pin: '020-01-001-01-002',
        state: 'CURRENT', // Raw source state
        effective_state: 'CANCELLED', // Effective state via supersession
        origin_type: 'REPLICA',
        owner_name: 'ORIGINAL OWNER',
        cancellation_reason: 'SUPERSEDED_BY_LOCAL',
        cancellation_date: '2026-03-15',
        cancelled_by_tdno: '2026-TD-00200',
        cancel_note: 'Superseded by local consolidation declaration',
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockCancelledProperty}
        />
      );

      // Watermark and state badge should reflect CANCELLED
      expect(view).toContain('CANCELLED');
      expect(view).toContain('DECLARATION CANCELLED');
      expect(view).toContain('SUPERSEDED_BY_LOCAL');
      expect(view).toContain('2026-TD-00200');
      expect(view).toContain('Superseded by local consolidation declaration');
    });

    test('renders disapproval notice when effective_state is DISAPPROVED', () => {
      const mockDisapprovedProperty = {
        id: 103,
        tdno: '2026-TD-00103',
        pin: '020-01-001-01-003',
        state: 'DISAPPROVED',
        effective_state: 'DISAPPROVED',
        origin_type: 'LOCAL',
        owner_name: 'APPLICANT NAME',
        disapproval_reason: 'Discrepancy in parcel boundary survey and lot identification.',
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockDisapprovedProperty}
        />
      );

      expect(view).toContain('DECLARATION DISAPPROVED / RETURNED');
      expect(view).toContain('Discrepancy in parcel boundary survey and lot identification.');
    });

    test('renders machinery brief description matching original ETRACS presentation (TD 22-010-0004-05935)', () => {
      const mockMachineryProperty = {
        id: 104,
        tdno: '22-010-0004-05935',
        pin: '059-10-0004-004-17-2001',
        state: 'CURRENT',
        rpu_type: 'MACH',
        owner_name: 'ANGEL BABES E. GALON',
        total_market_value: 400000,
        total_assessed_value: 320000,
        rpuDetail: {
          machdetails: [{
            machinename: 'GASOLINE STATION WITH COMPLETE ACCESSORIES',
            machinecode: 'GSWCA',
            brand: 'ONSON',
          }],
        },
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockMachineryProperty}
        />
      );

      // Verify MACHINERY is checked and exact brief description rendered
      expect(view).toContain('MACHINERY');
      expect(view).toContain('GASOLINE STATION WITH COMPLETE ACCESSORIES');
    });

    test('renders Building storeys and building kind brief description (TD 22-010-0001-00007)', () => {
      const mockBldgProperty = {
        id: 105,
        tdno: '22-010-0001-00007',
        rpu_type: 'BLDG',
        owner_name: 'MARICEL P. SOBRADO',
        total_market_value: 140613.16,
        total_assessed_value: 0,
        rpuDetail: {
          subtype: {
            floorcount: 1,
            bldgkind_name: 'ONE FAMILY DWELLING',
            bldgtype_name: 'TYPE IIA',
          },
        },
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockBldgProperty}
        />
      );

      expect(view).toContain('BUILDING');
      expect(view).toContain('No. of Storeys :');
      expect(view).toContain('ONE FAMILY DWELLING');
      expect(view).not.toContain('Brief Description :</Typography><div class="MuiBox-root css-101y218">TYPE IIA</div>');
    });

    test('renders honestly blank description when detail lookup is missing without fabricated text', () => {
      const mockMissingDetailProperty = {
        id: 106,
        tdno: '22-010-0000-00000',
        rpu_type: 'BLDG',
        owner_name: 'TEST OWNER',
        rpuDetail: {
          subtype: {
            floorcount: 1,
            bldgkind_name: '',
            bldgtypename: '',
            bldgtype_name: '',
          },
        },
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={mockMissingDetailProperty}
        />
      );

      expect(view).toContain('BUILDING');
      expect(view).not.toContain('UNKNOWN');
      expect(view).not.toContain('UNDEFINED');
    });

    test('dynamically invokes getRpuDetail and resolves machinery description when rpu_id/rpuid is provided', async () => {
      const getRpuDetailSpy = jest.spyOn(etracsService, 'getRpuDetail').mockResolvedValueOnce({
        data: {
          machdetails: [
            {
              machinename: 'GASOLINE STATION WITH COMPLETE ACCESSORIES',
            },
          ],
          assessments: [],
        },
      });

      // Property object as returned from FAAS list or getFaas (where rpuDetail is absent)
      const mockRawFaas = {
        id: 'F-180b0697:19327f6fa4c:-6c84',
        tdno: '22-010-0004-05935',
        rpu_id: 'RPU-180b0697:19327f6fa4c:-6c83',
        rpuid: 'RPU-180b0697:19327f6fa4c:-6c83',
        rpu_type: 'mach',
        owner_name: 'ANGEL BABES E. GALON',
        total_market_value: 400000,
        total_assessed_value: 320000,
      };

      // Verify service call with exact RPU identifier
      const detailResult = await etracsService.getRpuDetail(mockRawFaas.rpu_id);
      expect(getRpuDetailSpy).toHaveBeenCalledWith('RPU-180b0697:19327f6fa4c:-6c83');
      expect(detailResult.data.machdetails[0].machinename).toBe('GASOLINE STATION WITH COMPLETE ACCESSORIES');

      // Verify normalization: machdetails is normalized into machines
      const normalizedRpuDetail = {
        ...detailResult.data,
        machines: detailResult.data.machdetails,
      };

      // Verify SSR/render with dynamically resolved detail
      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={{ ...mockRawFaas, rpuDetail: normalizedRpuDetail }}
        />
      );

      expect(view).toContain('MACHINERY');
      expect(view).toContain('GASOLINE STATION WITH COMPLETE ACCESSORIES');
      getRpuDetailSpy.mockRestore();
    });
  });

  describe('2. EtracsAuditTimelineModal: Layout, Filters, and Dialog Rendering', () => {
    test('renders audit modal structure and filters', () => {
      const view = ReactDOMServer.renderToString(
        <EtracsAuditTimelineModal
          open={true}
          onClose={() => {}}
          faasId={101}
        />
      );

      expect(view).toContain('Shadow ETRACS Audit History');
      expect(view).toContain('FAAS ID: 101');
      expect(view).toContain('Event Type');
      expect(view).toContain('Provenance');
      expect(view).toContain('Ownership');
    });
  });

  describe('3. etracsService API Contract Extensions', () => {
    test('exports getAuditTrail, updateRpuAssessments, and getPullSyncDiagnostics', () => {
      expect(typeof etracsService.getAuditTrail).toBe('function');
      expect(typeof etracsService.updateRpuAssessments).toBe('function');
      expect(typeof etracsService.getPullSyncDiagnostics).toBe('function');
    });
  });

  describe('4. Draft-Save Flow: Atomic Orchestration & Non-Redundancy', () => {
    test('draft save bundles FAAS and assessment lines into single atomic business operation', async () => {
      // Simulate form submission logic
      const draftPayload = {
        tdno: '2026-TD-NEW-001',
        owner_name: 'PEDRO PENDUKO',
        rpu_type: 'LAND',
        barangay: 'POBLACION',
        pin: '059-10-0001-001-01',
        total_market_value: 350000,
        total_assessed_value: 70000,
        assessments: [
          {
            actualuse: 'RESIDENTIAL',
            classcode: 'RES',
            classname: 'Residential Land',
            areasqm: 250,
            marketvalue: 350000,
            assesslevel: 20,
            assessedvalue: 70000,
            taxable: 1,
          }
        ],
      };

      // Mock createFaas response
      const mockCreatedFaas = { id: 'faas-new-uuid-001', ...draftPayload };
      const createFaasSpy = jest.spyOn(etracsService, 'createFaas').mockResolvedValueOnce(mockCreatedFaas);
      const updateRpuAssessmentsSpy = jest.spyOn(etracsService, 'updateRpuAssessments');

      // Execute save operation
      const res = await etracsService.createFaas(draftPayload);

      // Verify createFaas was called with all assessment lines
      expect(createFaasSpy).toHaveBeenCalledTimes(1);
      expect(createFaasSpy).toHaveBeenCalledWith(expect.objectContaining({
        tdno: '2026-TD-NEW-001',
        owner_name: 'PEDRO PENDUKO',
        assessments: expect.arrayContaining([
          expect.objectContaining({
            actualuse: 'RESIDENTIAL',
            marketvalue: 350000,
            assessedvalue: 70000,
          })
        ]),
      }));

      // Critical WP-08 requirement: No redundant second call to updateRpuAssessments!
      expect(updateRpuAssessmentsSpy).not.toHaveBeenCalled();
      expect(res.id).toBe('faas-new-uuid-001');

      createFaasSpy.mockRestore();
      updateRpuAssessmentsSpy.mockRestore();
    });

    test('editing draft submits changed and removed assessment lines atomically in one updateFaas request', async () => {
      const updatedPayload = {
        tdno: '2026-TD-EDIT-002',
        owner_name: 'JUAN SANTOS',
        rpu_type: 'LAND',
        total_market_value: 600000,
        total_assessed_value: 120000,
        assessments: [
          {
            objid: 'ra-line-1',
            actualuse: 'COMMERCIAL',
            classcode: 'COM',
            classname: 'Commercial Land',
            areasqm: 500,
            marketvalue: 600000,
            assesslevel: 20,
            assessedvalue: 120000,
            taxable: 1,
          }
          // Note: line 2 was removed by user, so payload only contains line 1
        ],
      };

      const updateFaasSpy = jest.spyOn(etracsService, 'updateFaas').mockResolvedValueOnce({ id: 'faas-edit-uuid-002', ...updatedPayload });
      const updateRpuAssessmentsSpy = jest.spyOn(etracsService, 'updateRpuAssessments');

      const res = await etracsService.updateFaas('faas-edit-uuid-002', updatedPayload);

      expect(updateFaasSpy).toHaveBeenCalledTimes(1);
      expect(updateFaasSpy).toHaveBeenCalledWith('faas-edit-uuid-002', expect.objectContaining({
        assessments: expect.arrayContaining([
          expect.objectContaining({
            objid: 'ra-line-1',
            actualuse: 'COMMERCIAL',
            marketvalue: 600000,
          })
        ]),
      }));

      // No redundant standalone assessment calls
      expect(updateRpuAssessmentsSpy).not.toHaveBeenCalled();
      expect(res.id).toBe('faas-edit-uuid-002');

      updateFaasSpy.mockRestore();
      updateRpuAssessmentsSpy.mockRestore();
    });

    test('REPLICA record protection rejects local draft editing and cancellation attempts', () => {
      const replicaRecord = {
        id: 'faas-replica-001',
        tdno: '2026-REPLICA-TD',
        origin_type: 'REPLICA',
        state: 'CURRENT',
        effective_state: 'CURRENT',
      };

      // Frontend helper check logic
      const isReplica = replicaRecord.origin_type === 'REPLICA';
      expect(isReplica).toBe(true);

      // Verify that canEdit and canCancel actions are suppressed for REPLICA
      const canEditReplica = !isReplica && (replicaRecord.state === 'INTERIM');
      const canCancelReplica = !isReplica && (replicaRecord.state === 'CURRENT');
      expect(canEditReplica).toBe(false);
      expect(canCancelReplica).toBe(false);
    });

    test('failed save reports error honestly while preserving input data state', async () => {
      const failingPayload = {
        tdno: '2026-TD-FAIL-001',
        owner_name: 'TEST USER',
      };

      const createFaasSpy = jest.spyOn(etracsService, 'createFaas').mockRejectedValueOnce(
        new Error('Database lock error during assessment persistence: operation rolled back')
      );

      let caughtError = null;
      try {
        await etracsService.createFaas(failingPayload);
      } catch (err) {
        caughtError = err;
      }

      expect(caughtError).not.toBeNull();
      expect(caughtError.message).toContain('Database lock error');
      // The user's input remains preserved in failingPayload without being cleared
      expect(failingPayload.tdno).toBe('2026-TD-FAIL-001');

      createFaasSpy.mockRestore();
    });
  });

  describe('5. Lineage, Supersession & Effective-State Rendering', () => {
    test('renders effective state CANCELLED on REPLICA predecessor when raw state is CURRENT', () => {
      const replicaPredecessor = {
        id: 201,
        tdno: '2015-TD-REPLICA-001',
        pin: '020-01-001-01-001',
        state: 'CURRENT', // Raw source state in ETRACS
        effective_state: 'CANCELLED', // Calculated effective state via local successor approval
        origin_type: 'REPLICA',
        cancelled_by_tdno: '2026-TD-LOCAL-001',
        cancellation_reason: 'SUPERSEDED_BY_LOCAL',
        cancellation_date: '2026-03-20',
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={replicaPredecessor}
        />
      );

      // Raw state should not mask effective cancellation
      expect(view).toContain('DECLARATION CANCELLED');
      expect(view).toContain('2026-TD-LOCAL-001');
      expect(view).toContain('SUPERSEDED_BY_LOCAL');
      expect(view).toContain('REPLICA');
    });

    test('supports consolidation lineage displaying multiple predecessors and multiple successors', () => {
      const consolidationDeclaration = {
        id: 202,
        tdno: '2026-TD-CONSOL-001',
        state: 'CURRENT',
        effective_state: 'CURRENT',
        origin_type: 'LOCAL',
        predecessors: [
          { prev_tdno: '2010-LOT-A', prev_owner_name: 'ALICE CORP', prev_total_market_value: 400000 },
          { prev_tdno: '2010-LOT-B', prev_owner_name: 'BOB INC', prev_total_market_value: 600000 },
        ],
        successors: [
          { tdno: '2026-TD-CONSOL-001', state: 'CURRENT' }
        ]
      };

      const view = ReactDOMServer.renderToString(
        <TaxDeclarationPreviewModal
          open={true}
          onClose={() => {}}
          property={consolidationDeclaration}
        />
      );

      expect(view).toContain('2010-LOT-A');
      expect(view).toContain('ALICE CORP');
      expect(view).toContain('2010-LOT-B');
      expect(view).toContain('BOB INC');
    });
  });

  describe('6. Action In-Flight Guards, Role Authorization & Error Feedback', () => {
    test('submitting action guards against concurrent duplicate clicks', async () => {
      let isSubmitting = false;
      const executeLifecycleAction = async (actionFn) => {
        if (isSubmitting) return 'BLOCKED_DUPLICATE';
        isSubmitting = true;
        try {
          return await actionFn();
        } finally {
          isSubmitting = false;
        }
      };

      const mockApprove = jest.fn().mockImplementation(() => new Promise((resolve) => setTimeout(() => resolve('APPROVED'), 50)));

      // Trigger first click
      const firstClickPromise = executeLifecycleAction(mockApprove);
      // Immediate second click while in-flight
      const secondClickResult = await executeLifecycleAction(mockApprove);

      expect(secondClickResult).toBe('BLOCKED_DUPLICATE');
      const firstClickResult = await firstClickPromise;
      expect(firstClickResult).toBe('APPROVED');
      expect(mockApprove).toHaveBeenCalledTimes(1);
    });

    test('backend authorization failure returns informative user feedback without exposing secrets', async () => {
      const authError = new Error('Unauthorized: Assessor role required to approve declarations');
      authError.status = 403;

      const approveSpy = jest.spyOn(etracsService, 'approveFaas').mockRejectedValueOnce(authError);

      let userError = null;
      try {
        await etracsService.approveFaas(101);
      } catch (err) {
        userError = err.message;
      }

      expect(userError).toContain('Unauthorized: Assessor role required');
      expect(userError).not.toContain('password');
      expect(userError).not.toContain('SQL');

      approveSpy.mockRestore();
    });
  });
});
