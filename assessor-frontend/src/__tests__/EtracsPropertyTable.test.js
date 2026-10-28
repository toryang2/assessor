import React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import EtracsPropertyTable from '../components/EtracsPropertyTable/EtracsPropertyTable';
import { etracsService } from '../utils/api';

// Mock AuthContext
jest.mock('../contexts/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, username: 'admin' },
    isSuperAdmin: true,
    isAdmin: true,
    isAssessor: true,
    canManage: true,
  }),
}));

// Mock etracsService
jest.mock('../utils/api', () => ({
  etracsService: {
    getFaasList: jest.fn(),
    getStats: jest.fn(),
    getTransactionTypes: jest.fn(),
    getFaas: jest.fn(),
    deleteFaas: jest.fn(),
    cancelFaas: jest.fn(),
    submitFaas: jest.fn(),
    approveFaas: jest.fn(),
    disapproveFaas: jest.fn(),
  },
}));

// Mock modals to isolate table testing
jest.mock('../components/EtracsPropertyFormModal/EtracsPropertyFormModal', () => () => <div data-testid="mock-form-modal" />);
jest.mock('../components/EtracsPropertyTable/TaxDeclarationPreviewModal', () => ({ open, property }) => (
  open ? <div data-testid="mock-preview-modal">{property?.tdno}</div> : null
));
jest.mock('../components/EtracsPropertyTable/EtracsAuditTimelineModal', () => () => <div data-testid="mock-audit-modal" />);

describe('EtracsPropertyTable Layout, Sticky Actions & Expandable Owner Tests', () => {
  let container = null;
  let root = null;

  const shortOwnerRow = {
    id: 1,
    tdno: '2026-TD-00001',
    pin: '020-01-001-01-001',
    owner_name: 'JUAN DELA CRUZ',
    taxpayer_name: 'JUAN DELA CRUZ',
    barangay: 'POBLACION',
    rpu_type: 'LAND',
    classification: 'RESIDENTIAL',
    actualuse: 'RESIDENTIAL',
    total_market_value: 500000,
    total_assessed_value: 100000,
    txntype_code: 'TR01',
    origin_type: 'LOCAL',
    state: 'CURRENT',
    effective_state: 'CURRENT',
  };

  const longOwnerRow = {
    id: 2,
    tdno: '2026-TD-00002',
    pin: '020-01-001-01-002',
    owner_name: 'MARIA SANTOS MARRIED TO PEDRO SANTOS AND CO-OWNERS DELA CRUZ RESIDENTIAL PROPERTY HOLDINGS',
    taxpayer_name: 'MARIA SANTOS MARRIED TO PEDRO SANTOS',
    barangay: 'SAN AGUSTIN',
    rpu_type: 'BLDG',
    classification: 'COMMERCIAL',
    actualuse: 'COMMERCIAL',
    total_market_value: 1200000,
    total_assessed_value: 480000,
    txntype_code: 'TR01',
    origin_type: 'LOCAL',
    state: 'FORAPPROVAL',
    effective_state: 'FORAPPROVAL',
  };

  beforeEach(() => {
    jest.clearAllMocks();
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    etracsService.getStats.mockResolvedValue({
      total: 2,
      current: 1,
      forapproval: 1,
      interim: 0,
      cancelled: 0,
    });
    etracsService.getTransactionTypes.mockResolvedValue([
      { code: 'TR01', name: 'Simple Transfer' },
    ]);
  });

  afterEach(() => {
    act(() => {
      root.unmount();
    });
    container.remove();
    container = null;
  });

  test('renders 5 skeleton stats cards immediately and updates with loaded values', async () => {
    etracsService.getFaasList.mockResolvedValue({ data: [shortOwnerRow], total: 1 });

    await act(async () => {
      root.render(<EtracsPropertyTable />);
    });

    expect(container.textContent).toContain('Total Records');
    expect(container.textContent).toContain('Current');
    expect(container.textContent).toContain('For Approval');
    expect(container.textContent).toContain('Draft / Interim');
    expect(container.textContent).toContain('Cancelled');

    expect(container.textContent).toContain('TD No.');
    expect(container.textContent).toContain('Owner');
    expect(container.textContent).toContain('PIN');
    expect(container.textContent).toContain('Actions');

    // Wait for async row to populate
    await act(async () => {
      await Promise.resolve();
    });

    expect(container.textContent).toContain('2026-TD-00001');
  });

  test('applies sticky positioning to Actions header and body cells with 100px fixed width, opaque backgrounds and stacking order', async () => {
    etracsService.getFaasList.mockResolvedValue({ data: [shortOwnerRow], total: 1 });

    await act(async () => {
      root.render(<EtracsPropertyTable />);
    });
    await act(async () => {
      await Promise.resolve();
    });

    // Check colgroup exists and sets 100px for Actions
    const cols = container.querySelectorAll('col');
    expect(cols.length).toBe(13);
    expect(cols[12].style.width).toBe('100px');

    // Check Actions header cell has sticky styling and 100px width
    const headerCells = container.querySelectorAll('th');
    const actionsHeader = Array.from(headerCells).find(c => c.textContent.trim() === 'Actions');
    expect(actionsHeader).toBeTruthy();
    const headerStyle = window.getComputedStyle(actionsHeader);
    expect(headerStyle.position).toBe('sticky');
    expect(headerStyle.right).toBe('0px');
    expect(headerStyle.zIndex).toBe('3');
    expect(headerStyle.width).toBe('100px');

    // Check body Actions cell has sticky styling and 100px width
    const actionsBodyCell = container.querySelector('.sticky-actions-cell');
    expect(actionsBodyCell).toBeTruthy();
    const bodyStyle = window.getComputedStyle(actionsBodyCell);
    expect(bodyStyle.position).toBe('sticky');
    expect(bodyStyle.right).toBe('0px');
    expect(bodyStyle.zIndex).toBe('1');
    expect(bodyStyle.width).toBe('100px');
  });

  test('expandable Owner field: View button appears only for long text, expands/collapses on click, and does not open declaration modal', async () => {
    etracsService.getFaasList.mockResolvedValue({
      data: [shortOwnerRow, longOwnerRow],
      total: 2,
    });
    etracsService.getFaas.mockResolvedValue(longOwnerRow);

    await act(async () => {
      root.render(<EtracsPropertyTable />);
    });
    await act(async () => {
      await Promise.resolve();
    });

    // Short owner row (id: 1) should NOT have a View button
    const buttons = Array.from(container.querySelectorAll('button'));
    const viewButtons = buttons.filter(b => b.textContent.trim() === 'View');
    expect(viewButtons.length).toBe(1); // Only for row 2 (long owner)

    // Long owner typography element is initially collapsed (white-space: nowrap)
    const longOwnerSpan = Array.from(container.querySelectorAll('p')).find(p =>
      p.textContent.includes('MARIA SANTOS MARRIED TO PEDRO SANTOS')
    );
    expect(longOwnerSpan).toBeTruthy();
    expect(window.getComputedStyle(longOwnerSpan).whiteSpace).toBe('nowrap');

    // Click "View" button to expand
    await act(async () => {
      viewButtons[0].dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    // Button text toggles to "Less"
    const updatedButtons = Array.from(container.querySelectorAll('button'));
    const lessButton = updatedButtons.find(b => b.textContent.trim() === 'Less');
    expect(lessButton).toBeTruthy();

    // Long owner text is now normal (expanded wrapping)
    expect(window.getComputedStyle(longOwnerSpan).whiteSpace).toBe('normal');

    // Clicking View/Less must NOT have called etracsService.getFaas (did not open declaration modal)
    expect(etracsService.getFaas).not.toHaveBeenCalled();
    expect(container.querySelector('[data-testid="mock-preview-modal"]')).toBeNull();

    // Click "Less" to collapse back
    await act(async () => {
      lessButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    // Button toggles back to "View"
    const finalButtons = Array.from(container.querySelectorAll('button'));
    const finalViewButton = finalButtons.find(b => b.textContent.trim() === 'View');
    expect(finalViewButton).toBeTruthy();
    expect(window.getComputedStyle(longOwnerSpan).whiteSpace).toBe('nowrap');
  });

  test('clicking row opens declaration preview modal while clicking actions does not trigger row click', async () => {
    etracsService.getFaasList.mockResolvedValue({
      data: [shortOwnerRow],
      total: 1,
    });
    etracsService.getFaas.mockResolvedValue(shortOwnerRow);

    await act(async () => {
      root.render(<EtracsPropertyTable />);
    });
    await act(async () => {
      await Promise.resolve();
    });

    // Click on TD number (row click)
    const tdLink = Array.from(container.querySelectorAll('p')).find(p => p.textContent.trim() === '2026-TD-00001');
    expect(tdLink).toBeTruthy();

    await act(async () => {
      tdLink.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    expect(etracsService.getFaas).toHaveBeenCalledWith(1);
    expect(container.querySelector('[data-testid="mock-preview-modal"]')).toBeTruthy();
  });

  test('stable table layout geometry: pagination remains outside vertical scroll region', async () => {
    etracsService.getFaasList.mockResolvedValue({
      data: [shortOwnerRow],
      total: 1,
    });

    await act(async () => {
      root.render(<EtracsPropertyTable />);
    });

    // Verify TableContainer has overflow: auto
    const tableContainer = container.querySelector('.MuiTableContainer-root');
    expect(tableContainer).toBeTruthy();

    // Verify pagination is a sibling to TableContainer inside the outer Paper
    const pagination = container.querySelector('.MuiTablePagination-root');
    expect(pagination).toBeTruthy();
    expect(tableContainer.contains(pagination)).toBe(false);
  });
});
