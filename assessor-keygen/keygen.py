import customtkinter as ctk
import tkinter as tk
from tkinter import messagebox
import hashlib

# Configuration
ctk.set_appearance_mode("System")  # Modes: "System" (standard), "Dark", "Light"
ctk.set_default_color_theme("blue")  # Themes: "blue" (standard), "green", "dark-blue"

SECRET_SALT = "AssessorSecretKey2026!"

class KeygenApp(ctk.CTk):
    def __init__(self):
        super().__init__()

        # Window setup
        self.title("Assessor Hardware Keygen")
        self.geometry("450x300")
        self.resizable(False, False)
        self.eval('tk::PlaceWindow . center')

        # Main frame
        self.main_frame = ctk.CTkFrame(self, corner_radius=10)
        self.main_frame.pack(pady=20, padx=20, fill="both", expand=True)

        # Title Label
        self.title_label = ctk.CTkLabel(self.main_frame, text="Assessor Hardware Keygen", font=ctk.CTkFont(size=20, weight="bold"))
        self.title_label.pack(pady=(15, 15))

        # Hardware ID Input
        self.hardware_id_var = tk.StringVar()
        self.hw_entry = ctk.CTkEntry(self.main_frame, textvariable=self.hardware_id_var, 
                                     placeholder_text="Enter Hardware ID...", 
                                     width=300, height=35, font=ctk.CTkFont(size=14))
        self.hw_entry.pack(pady=(0, 15))

        # Generate Button
        self.gen_btn = ctk.CTkButton(self.main_frame, text="Generate Activation Key", 
                                     command=self.generate_key, width=300, height=40,
                                     font=ctk.CTkFont(size=14, weight="bold"))
        self.gen_btn.pack(pady=(0, 15))

        # Activation Key Output
        self.activation_key_var = tk.StringVar()
        self.key_entry = ctk.CTkEntry(self.main_frame, textvariable=self.activation_key_var, 
                                      width=300, height=35, font=ctk.CTkFont(size=14, weight="bold"),
                                      state="readonly", justify="center")
        self.key_entry.pack(pady=(0, 10))

        # Copy Button
        self.copy_btn = ctk.CTkButton(self.main_frame, text="Copy Key", 
                                      command=self.copy_key, width=150, height=30,
                                      fg_color="transparent", border_width=2,
                                      text_color=("gray10", "#DCE4EE"))
        self.copy_btn.pack(pady=(0, 10))

    def generate_key(self):
        hardware_id = self.hardware_id_var.get().strip()
        if not hardware_id:
            messagebox.showerror("Error", "Please enter a Hardware ID")
            return
            
        hash_obj = hashlib.sha256((hardware_id + SECRET_SALT).encode('utf-8'))
        activation_key = hash_obj.hexdigest()[:16].upper()
        
        self.activation_key_var.set(activation_key)

    def copy_key(self):
        key = self.activation_key_var.get()
        if key:
            self.clipboard_clear()
            self.clipboard_append(key)
            self.update()
            messagebox.showinfo("Success", "Activation Key copied to clipboard!")

if __name__ == "__main__":
    app = KeygenApp()
    app.mainloop()
