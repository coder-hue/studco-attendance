import LocalAuthentication
import SwiftUI

@main
struct StuCoAdminApp: App {
    @StateObject private var lock = AppLockModel()
    @StateObject private var attendance = AppModel()
    @AppStorage("stucoAppearance") private var appearance = "system"

    var body: some Scene {
        WindowGroup {
            Group {
                if lock.isUnlocked {
                    AttendanceRootView()
                        .environmentObject(lock)
                        .environmentObject(attendance)
                } else {
                    AppLockView().environmentObject(lock)
                }
            }
            .tint(StuCoTheme.blue)
            .preferredColorScheme(preferredColorScheme)
            .onOpenURL { attendance.handleDeepLink($0) }
        }
    }

    private var preferredColorScheme: ColorScheme? {
        switch appearance {
        case "light": .light
        case "dark": .dark
        default: nil
        }
    }
}

@MainActor
final class AppLockModel: ObservableObject {
    @Published var isUnlocked = false
    @Published var isWorking = false
    @Published var error: String?

    init() {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-UITestingBypassLock") {
            isUnlocked = true
        }
        #endif
    }

    func unlock() async {
        guard !isWorking else { return }
        isWorking = true; error = nil
        let context = LAContext()
        var authError: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthentication, error: &authError) else {
            error = "Turn on Face ID or a device passcode to protect the StuCo controls."
            isWorking = false; return
        }
        do {
            try await context.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: "Open private Student Council controls")
            isUnlocked = true
        } catch {
            self.error = "Couldn’t verify your identity. Try again."
        }
        isWorking = false
    }

    func lock() { isUnlocked = false }
}

struct AppLockView: View {
    @EnvironmentObject private var lock: AppLockModel
    @State private var attempted = false
    var body: some View {
        VStack(alignment: .leading, spacing: 24) {
            Spacer()
            Image(systemName: "building.columns.fill").font(.system(size: 58)).foregroundStyle(StuCoTheme.blue)
            Text("StuCo Attendance").font(.system(size: 44, weight: .black, design: .rounded))
            Text("Create events, display QR codes, and manage check-ins.").font(.title3).foregroundStyle(.secondary)
            if let error = lock.error { Text(error).foregroundStyle(StuCoTheme.coral) }
            Button { Task { await lock.unlock() } } label: {
                HStack { Image(systemName: "faceid"); Text(lock.isWorking ? "Verifying…" : "Unlock"); Spacer(); Image(systemName: "arrow.right") }.fontWeight(.bold).padding()
            }
            .buttonStyle(.plain).foregroundStyle(.white).background(StuCoTheme.blue, in: RoundedRectangle(cornerRadius: 16)).disabled(lock.isWorking)
            Spacer()
        }
        .padding(28).background(Color(.systemBackground).ignoresSafeArea())
        .task { guard !attempted else { return }; attempted = true; await lock.unlock() }
    }
}

enum StuCoTheme {
    static let ink = Color.primary
    static let blue = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 0.31, green: 0.49, blue: 1.00, alpha: 1)
            : UIColor(red: 0.05, green: 0.25, blue: 0.72, alpha: 1)
    })
    static let cream = Color(.systemGroupedBackground)
    static let coral = Color(uiColor: UIColor { traits in
        traits.userInterfaceStyle == .dark ? .systemRed : UIColor(red: 0.82, green: 0.16, blue: 0.16, alpha: 1)
    })
    static let lime = Color(uiColor: .systemGreen)
}
